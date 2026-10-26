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
            ->withCount('messages')
            ->with(['customer', 'messages' => fn($q) => $q->latest()->limit(1)->with('attachments')]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['unread'])) {
            $query->where('admin_unread', true);
        }
        if (!empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn($q) => $q
                ->where('subject', 'like', "%{$s}%")
                ->orWhere('ticket_number', 'like', "%{$s}%")
                ->orWhere('customer_email', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
                // description = the customer's messages on the ticket
                ->orWhereHas('messages', fn($m) => $m->where('body', 'like', "%{$s}%"))
            );
        }

        $query->orderBy('updated_at', 'desc')->orderBy('id', 'desc');
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $query->paginate($perPage);
    }

    public function findById(int $id, int $companyId): ?SupportTicket
    {
        return $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->with(['customer', 'messages' => fn($q) => $q->orderBy('created_at')->with('attachments')])
            ->first();
    }

    public function findByNumber(string $ticketNumber, int $companyId): ?SupportTicket
    {
        return $this->model
            ->where('ticket_number', $ticketNumber)
            ->where('company_id', $companyId)
            ->with(['messages' => fn($q) => $q->orderBy('created_at')->with('attachments')])
            ->first();
    }

    public function findByNumberAndGuestToken(string $ticketNumber, int $companyId, string $guestAccessToken): ?SupportTicket
    {
        return $this->model
            ->where('ticket_number', $ticketNumber)
            ->where('company_id', $companyId)
            ->where('guest_access_token', $guestAccessToken)
            ->with(['messages' => fn($q) => $q->orderBy('created_at')->with('attachments')])
            ->first();
    }

    public function findByCustomer(int $customerId, int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model
            ->where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->with(['messages' => fn($q) => $q->latest()->limit(1)->with('attachments')]);

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
        $extra = in_array($status, ['resolved', 'closed'], true) ? ['resolved_at' => now()] : [];
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

    public function addMessage(int $ticketId, ?string $body, string $senderType, ?int $customerId, ?string $senderName): SupportMessage
    {
        $message = $this->messageModel->create([
            'ticket_id'   => $ticketId,
            'body'        => $body,
            'sender_type' => $senderType,
            'customer_id' => $customerId,
            'sender_name' => $senderName,
        ]);
        $this->model->where('id', $ticketId)->update(
            $senderType === 'customer'
                ? ['updated_at' => now(), 'admin_unread' => true]
                : ['updated_at' => now(), 'admin_unread' => false, 'last_read_at' => now()]
        );

        return $message;
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
            'unread'      => $this->model->where('company_id', $companyId)->where('admin_unread', true)->count(),
        ];
    }

    public function markRead(int $id, int $companyId): void
    {
        $this->model->where('id', $id)->where('company_id', $companyId)->where('admin_unread', true)
            ->update(['admin_unread' => false, 'last_read_at' => now()]);
    }

    public function delete(int $id, int $companyId): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->delete();
    }
}
