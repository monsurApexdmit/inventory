<?php

namespace App\Repositories\Eloquent;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Repositories\Contracts\ISupportTicketRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class SupportTicketRepository implements ISupportTicketRepository
{
    public function __construct(
        private readonly SupportTicket $model,
        private readonly SupportMessage $messageModel,
    ) {}

    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model->where('company_id', $companyId)
            ->with(['customer', 'messages' => fn($q) => $q->latest()->limit(1)]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn($q) => $q
                ->where('subject', 'like', "%{$s}%")
                ->orWhere('ticket_number', 'like', "%{$s}%")
                ->orWhere('customer_email', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
            );
        }

        $query->orderBy('created_at', 'desc');
        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->paginate($perPage);
    }

    public function findById(int $id, int $companyId): ?SupportTicket
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with(['customer', 'messages' => fn($q) => $q->orderBy('created_at')])
            ->first();
    }

    public function findByNumber(string $ticketNumber, int $companyId): ?SupportTicket
    {
        return $this->model
            ->where('ticket_number', $ticketNumber)
            ->where('company_id', $companyId)
            ->with(['messages' => fn($q) => $q->orderBy('created_at')])
            ->first();
    }

    public function findByCustomer(int $customerId, int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model
            ->where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->with(['messages' => fn($q) => $q->latest()->limit(1)]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderBy('created_at', 'desc');
        $perPage = min($filters['per_page'] ?? 10, 50);

        return $query->paginate($perPage);
    }

    public function create(array $data): SupportTicket
    {
        return $this->model->create($data);
    }

    public function updateStatus(int $id, int $companyId, string $status): bool
    {
        $extra = $status === 'resolved' ? ['resolved_at' => now()] : [];
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update(array_merge(['status' => $status], $extra));
    }

    public function updatePriority(int $id, int $companyId, string $priority): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update(['priority' => $priority]);
    }

    public function addMessage(int $ticketId, string $body, string $senderType, ?int $customerId, ?string $senderName): void
    {
        $this->messageModel->create([
            'ticket_id'   => $ticketId,
            'body'        => $body,
            'sender_type' => $senderType,
            'customer_id' => $customerId,
            'sender_name' => $senderName,
        ]);
    }

    public function countByStatus(int $companyId): array
    {
        $counts = $this->model->where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'open'        => $counts['open']        ?? 0,
            'in_progress' => $counts['in_progress']  ?? 0,
            'resolved'    => $counts['resolved']     ?? 0,
            'closed'      => $counts['closed']       ?? 0,
            'total'       => array_sum($counts),
        ];
    }

    public function delete(int $id, int $companyId): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->delete();
    }
}
