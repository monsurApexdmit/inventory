<?php

namespace App\Repositories\Eloquent;

use App\Models\Notification;
use App\Repositories\Contracts\INotificationRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository implements INotificationRepository
{
    public function __construct(private readonly Notification $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model->where('company_id', $companyId);

        if (isset($filters['unread_only']) && $filters['unread_only']) {
            $query->whereNull('read_at');
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->paginate($perPage);
    }

    public function findById(int $id, int $companyId): ?Notification
    {
        return $this->model->where('id', $id)->where('company_id', $companyId)->first();
    }

    public function create(int $companyId, string $type, string $title, string $message, ?array $data = null): Notification
    {
        return $this->model->create([
            'company_id' => $companyId,
            'type'       => $type,
            'title'      => $title,
            'message'    => $message,
            'data'       => $data,
        ]);
    }

    public function markAsRead(int $id, int $companyId): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAsUnread(int $id, int $companyId): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update(['read_at' => null]);
    }

    public function markAllAsRead(int $companyId): int
    {
        return $this->model
            ->where('company_id', $companyId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function delete(int $id, int $companyId): bool
    {
        return (bool) $this->model
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->delete();
    }

    public function bulkDelete(int $companyId, array $ids): int
    {
        return $this->model
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->delete();
    }

    public function deleteOld(int $companyId, int $keepDays): int
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('created_at', '<', now()->subDays($keepDays))
            ->delete();
    }

    public function countUnread(int $companyId): int
    {
        return $this->model
            ->where('company_id', $companyId)
            ->whereNull('read_at')
            ->count();
    }
}
