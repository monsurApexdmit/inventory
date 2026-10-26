<?php

namespace App\Repositories\Eloquent;

use App\DTOs\Notification\NotificationDTO;
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

        $this->applyStatus($query, $filters);
        $this->applyType($query, $filters['type'] ?? null);

        $dir = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';
        $query->orderBy('created_at', $dir)->orderBy('id', $dir);

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->paginate($perPage);
    }

    private function applyStatus($query, array $filters): void
    {
        if (!empty($filters['unread_only'])) {
            $query->whereNull('read_at');
        } elseif (!empty($filters['read_only'])) {
            $query->whereNotNull('read_at');
        }
    }

    /** $type may be a frontend category (order, stock_alert, ...) or a raw internal type. */
    private function applyType($query, ?string $type): void
    {
        if (empty($type) || $type === 'all') {
            return;
        }
        if (in_array($type, array_values(NotificationDTO::TYPE_MAP), true) || $type === 'system') {
            $own = NotificationDTO::typesForCategory($type);
            if ($type === 'system') {
                $others = array_keys(array_filter(NotificationDTO::TYPE_MAP, fn($c) => $c !== 'system'));
                $query->whereNotIn('type', $others);
            } else {
                $query->whereIn('type', $own);
            }
            return;
        }
        $query->where('type', $type);
    }

    /** Count per frontend category, honouring the status filter only. */
    public function categoryCounts(int $companyId, array $filters): array
    {
        $query = $this->model->where('company_id', $companyId);
        $this->applyStatus($query, $filters);
        $rows = $query->selectRaw('type, count(*) as c')->groupBy('type')->pluck('c', 'type');

        $out = ['all' => 0, 'order' => 0, 'stock_alert' => 0, 'payment' => 0, 'support' => 0, 'review' => 0, 'system' => 0];
        foreach ($rows as $type => $c) {
            $cat = NotificationDTO::TYPE_MAP[$type] ?? 'system';
            $out[$cat] += (int) $c;
            $out['all'] += (int) $c;
        }
        return $out;
    }

    public function allIds(int $companyId): array
    {
        return $this->model->where('company_id', $companyId)->pluck('id')->all();
    }

    public function deleteAll(int $companyId): int
    {
        return $this->model->where('company_id', $companyId)->delete();
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

    public function findUnreadIdsByCompany(int $companyId): array
    {
        return $this->model
            ->where('company_id', $companyId)
            ->whereNull('read_at')
            ->pluck('id')
            ->all();
    }

    public function findExistingIdsByCompany(int $companyId, array $ids): array
    {
        return $this->model
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
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
