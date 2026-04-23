<?php

namespace App\Repositories\Contracts;

use App\Models\Notification;
use Illuminate\Pagination\LengthAwarePaginator;

interface INotificationRepository
{
    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator;

    public function findById(int $id, int $companyId): ?Notification;

    public function create(int $companyId, string $type, string $title, string $message, ?array $data = null): Notification;

    public function markAsRead(int $id, int $companyId): bool;

    public function markAsUnread(int $id, int $companyId): bool;

    public function markAllAsRead(int $companyId): int;

    public function delete(int $id, int $companyId): bool;

    public function bulkDelete(int $companyId, array $ids): int;

    public function deleteOld(int $companyId, int $keepDays): int;

    public function countUnread(int $companyId): int;
}
