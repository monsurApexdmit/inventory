<?php

namespace App\Services\Support;

use App\DTOs\Support\SupportMapper;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketMessageSent;
use App\Events\Support\SupportTicketPriorityUpdated;
use App\Events\Support\SupportTicketStatusUpdated;
use App\Repositories\Contracts\ISupportTicketRepository;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportTicketService
{
    private readonly SupportMapper $mapper;

    public function __construct(private readonly ISupportTicketRepository $repository)
    {
        $this->mapper = new SupportMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginator = $this->repository->findByCompany($companyId, $filters);

        return [
            'data'         => array_map(fn($t) => $this->mapper->toDTO($t)->toArray(), $paginator->items()),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ];
    }

    public function show(int $id, int $companyId): array
    {
        $ticket = $this->repository->findById($id, $companyId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }
        return $this->mapper->toDTO($ticket)->toArray();
    }

    public function create(int $companyId, array $data, ?int $customerId = null): array
    {
        $ticketNumber = $this->generateTicketNumber($companyId);

        $ticket = $this->repository->create([
            'company_id'     => $companyId,
            'customer_id'    => $customerId,
            'ticket_number'  => $ticketNumber,
            'subject'        => $data['subject'],
            'status'         => 'open',
            'priority'       => $data['priority'] ?? 'medium',
            'category'       => $data['category'] ?? 'general',
            'customer_name'  => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
        ]);

        if (!empty($data['message'])) {
            $this->repository->addMessage(
                ticketId:   $ticket->id,
                body:       $data['message'],
                senderType: 'customer',
                customerId: $customerId,
                senderName: $data['customer_name'] ?? null,
            );
        }

        $result = $this->show($ticket->id, $companyId);
        $this->dispatchBroadcast(new SupportTicketCreated($companyId, $result));

        return $result;
    }

    public function reply(int $id, int $companyId, string $body, string $senderType, ?int $customerId = null, ?string $senderName = null): array
    {
        $ticket = $this->repository->findById($id, $companyId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }

        $this->repository->addMessage($id, $body, $senderType, $customerId, $senderName);

        // Re-open if staff replies to a closed/resolved ticket — or move to in_progress
        if ($senderType === 'staff' && $ticket->status === 'open') {
            $this->repository->updateStatus($id, $companyId, 'in_progress');
        }

        $result = $this->show($id, $companyId);
        $lastMessage = collect($result['messages'])->last();
        if ($lastMessage) {
            $this->dispatchBroadcast(new SupportTicketMessageSent($companyId, $id, $lastMessage));
        }

        return $result;
    }

    public function updateStatus(int $id, int $companyId, string $status): array
    {
        $ticket = $this->repository->findById($id, $companyId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }
        $this->repository->updateStatus($id, $companyId, $status);
        $result = $this->show($id, $companyId);
        $this->dispatchBroadcast(new SupportTicketStatusUpdated($companyId, $id, $status));
        return $result;
    }

    public function updatePriority(int $id, int $companyId, string $priority): array
    {
        $ticket = $this->repository->findById($id, $companyId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }
        $this->repository->updatePriority($id, $companyId, $priority);
        $result = $this->show($id, $companyId);
        $this->dispatchBroadcast(new SupportTicketPriorityUpdated($companyId, $id, $priority));
        return $result;
    }

    public function listForCustomer(int $customerId, int $companyId, array $filters): array
    {
        $paginator = $this->repository->findByCustomer($customerId, $companyId, $filters);

        return [
            'data'         => array_map(fn($t) => $this->mapper->toDTO($t)->toArray(), $paginator->items()),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ];
    }

    public function showForCustomer(int $id, int $customerId, int $companyId): array
    {
        $ticket = $this->repository->findById($id, $companyId);
        if (!$ticket || $ticket->customer_id !== $customerId) {
            throw new HttpException(404, 'Ticket not found');
        }
        return $this->mapper->toDTO($ticket)->toArray();
    }

    public function stats(int $companyId): array
    {
        return $this->repository->countByStatus($companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $deleted = $this->repository->delete($id, $companyId);
        if (!$deleted) {
            throw new HttpException(404, 'Ticket not found');
        }
    }

    private function generateTicketNumber(int $companyId): string
    {
        $prefix = 'TKT-' . str_pad($companyId, 3, '0', STR_PAD_LEFT) . '-';
        $suffix = strtoupper(substr(uniqid(), -6));
        return $prefix . $suffix;
    }

    private function dispatchBroadcast(object $event): void
    {
        if (config('broadcasting.default') === 'reverb' && !class_exists(\Pusher\Pusher::class)) {
            Log::warning('Skipping support broadcast because Pusher PHP SDK is missing.');
            return;
        }

        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Support broadcast failed: '.$e->getMessage());
        }
    }
}
