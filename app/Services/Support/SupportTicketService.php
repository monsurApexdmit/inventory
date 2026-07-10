<?php

namespace App\Services\Support;

use App\DTOs\Support\SupportMapper;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketMessageSent;
use App\Events\Support\SupportTicketPriorityUpdated;
use App\Events\Support\SupportTicketStatusUpdated;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Repositories\Contracts\ISupportTicketRepository;
use App\Services\Notification\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportTicketService
{
    private const MAX_ATTACHMENTS = 5;
    private readonly SupportMapper $mapper;

    public function __construct(
        private readonly ISupportTicketRepository $repository,
        private readonly NotificationService $notificationService,
    ) {
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
        $guestAccessToken = $customerId === null ? $this->generateGuestAccessToken() : null;

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
            'guest_access_token' => $guestAccessToken,
        ]);

        if (!empty($data['message']) || !empty($data['attachments'])) {
            $message = $this->repository->addMessage(
                ticketId:   $ticket->id,
                body:       $this->normalizeMessageBody($data['message'] ?? null),
                senderType: 'customer',
                customerId: $customerId,
                senderName: $data['customer_name'] ?? null,
            );
            $this->storeAttachments($message, $ticket->company_id, $data['attachments'] ?? []);
        }

        $result = $this->show($ticket->id, $companyId);
        $this->dispatchBroadcast(new SupportTicketCreated($companyId, $result));
        $this->notificationService->notifySupportTicketCreated(
            companyId: $companyId,
            ticketNumber: $result['ticketNumber'],
            subject: $result['subject'],
            customerName: $result['customerName'],
            ticketId: $result['id'],
        );

        return $result;
    }

    public function createGuestContact(int $companyId, array $data): array
    {
        $ticket = $this->create($companyId, $data, null);
        $model = $this->repository->findById($ticket['id'], $companyId);

        return [
            'ticket' => $ticket,
            'guestAccessToken' => $model?->guest_access_token,
        ];
    }

    public function reply(
        int $id,
        int $companyId,
        ?string $body,
        string $senderType,
        ?int $customerId = null,
        ?string $senderName = null,
        array $attachments = [],
    ): array
    {
        $ticket = $this->repository->findById($id, $companyId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }

        if ($this->normalizeMessageBody($body) === null && count($attachments) === 0) {
            throw new HttpException(422, 'Message or attachment is required');
        }

        $message = $this->repository->addMessage($id, $this->normalizeMessageBody($body), $senderType, $customerId, $senderName);
        $this->storeAttachments($message, $companyId, $attachments);

        // Re-open if staff replies to a closed/resolved ticket — or move to in_progress
        if ($senderType === 'staff' && $ticket->status === 'open') {
            $this->repository->updateStatus($id, $companyId, 'in_progress');
        }

        $result = $this->show($id, $companyId);
        $lastMessage = collect($result['messages'])->last();
        if ($lastMessage) {
            $this->dispatchBroadcast(new SupportTicketMessageSent($companyId, $id, $lastMessage));
        }

        if ($senderType === 'customer') {
            $this->notificationService->notifySupportMessageReceived(
                companyId: $companyId,
                ticketNumber: $result['ticketNumber'],
                subject: $result['subject'],
                senderName: $senderName ?? $result['customerName'],
                ticketId: $result['id'],
            );
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

    public function showForGuest(string $ticketNumber, string $guestAccessToken, int $companyId): array
    {
        $ticket = $this->repository->findByNumberAndGuestToken($ticketNumber, $companyId, $guestAccessToken);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }

        return $this->mapper->toDTO($ticket)->toArray();
    }

    public function replyForGuest(string $ticketNumber, string $guestAccessToken, int $companyId, ?string $body, array $attachments = []): array
    {
        $ticket = $this->repository->findByNumberAndGuestToken($ticketNumber, $companyId, $guestAccessToken);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket not found');
        }

        return $this->reply(
            id: $ticket->id,
            companyId: $companyId,
            body: $body,
            senderType: 'customer',
            customerId: null,
            senderName: $ticket->customer_name,
            attachments: $attachments,
        );
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

    private function generateGuestAccessToken(): string
    {
        return Str::random(40);
    }

    /**
     * @param UploadedFile[] $attachments
     */
    private function storeAttachments(SupportMessage $message, int $companyId, array $attachments): void
    {
        $files = array_values(array_filter($attachments, fn ($attachment) => $attachment instanceof UploadedFile));
        if (count($files) === 0) {
            return;
        }

        if (count($files) > self::MAX_ATTACHMENTS) {
            throw new HttpException(422, 'Too many attachments');
        }

        foreach ($files as $file) {
            $path = $file->store(
                sprintf('support/%d/%d', $companyId, $message->ticket_id),
                'public'
            );

            SupportMessageAttachment::create([
                'support_message_id' => $message->id,
                'ticket_id' => $message->ticket_id,
                'company_id' => $companyId,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize() ?: 0,
                'attachment_type' => $this->detectAttachmentType($file),
            ]);
        }
    }

    private function detectAttachmentType(UploadedFile $file): string
    {
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType() ?: '';

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'voice';
        }

        return 'file';
    }

    private function normalizeMessageBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $trimmed = trim($body);
        return $trimmed === '' ? null : $trimmed;
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
