<?php

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationDTO;
use App\DTOs\Notification\NotificationMapper;
use App\Repositories\Contracts\INotificationRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationService
{
    // Notification type constants
    public const TYPE_ORDER_PLACED         = 'order_placed';
    public const TYPE_ORDER_STATUS_CHANGED = 'order_status_changed';
    public const TYPE_LOW_STOCK            = 'low_stock';
    public const TYPE_STAFF_JOINED         = 'staff_joined';
    public const TYPE_COUPON_USED          = 'coupon_used';
    public const TYPE_RETURN_REQUESTED     = 'return_requested';
    public const TYPE_STOCK_TRANSFER       = 'stock_transfer';

    private readonly NotificationMapper $mapper;

    public function __construct(private readonly INotificationRepository $repository)
    {
        $this->mapper = new NotificationMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginator = $this->repository->findByCompany($companyId, $filters);

        $data = array_map(
            fn($n) => $this->mapper->toDTO($n)->toArray(),
            $paginator->items()
        );

        return [
            'data'         => $data,
            'unreadCount'  => $this->repository->countUnread($companyId),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ];
    }

    public function markAsRead(int $id, int $companyId): void
    {
        $notification = $this->repository->findById($id, $companyId);
        if (!$notification) {
            throw new HttpException(404, 'Notification not found');
        }
        $this->repository->markAsRead($id, $companyId);
    }

    public function markAsUnread(int $id, int $companyId): void
    {
        $notification = $this->repository->findById($id, $companyId);
        if (!$notification) {
            throw new HttpException(404, 'Notification not found');
        }
        $this->repository->markAsUnread($id, $companyId);
    }

    public function markAllAsRead(int $companyId): void
    {
        $this->repository->markAllAsRead($companyId);
    }

    public function countUnread(int $companyId): int
    {
        return $this->repository->countUnread($companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $notification = $this->repository->findById($id, $companyId);
        if (!$notification) {
            throw new HttpException(404, 'Notification not found');
        }
        $this->repository->delete($id, $companyId);
    }

    public function bulkDelete(int $companyId, array $ids): void
    {
        $this->repository->bulkDelete($companyId, $ids);
    }

    public function deleteOld(int $companyId, int $keepDays = 30): array
    {
        $count = $this->repository->deleteOld($companyId, $keepDays);
        return ['deleted' => $count];
    }

    // ─── Creation helpers called by other services ───────────────────────────

    public function notifyOrderPlaced(int $companyId, string $invoiceNo, string $customerName, float $amount): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_ORDER_PLACED,
            'New Order Received',
            "Order {$invoiceNo} placed by {$customerName} for $" . number_format($amount, 2),
            ['invoiceNo' => $invoiceNo, 'customerName' => $customerName, 'amount' => $amount]
        );
    }

    public function notifyOrderStatusChanged(int $companyId, string $invoiceNo, string $oldStatus, string $newStatus): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_ORDER_STATUS_CHANGED,
            'Order Status Updated',
            "Order {$invoiceNo} changed from {$oldStatus} to {$newStatus}",
            ['invoiceNo' => $invoiceNo, 'oldStatus' => $oldStatus, 'newStatus' => $newStatus]
        );
    }

    public function notifyLowStock(int $companyId, string $productName, int $currentQty, int $threshold): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_LOW_STOCK,
            'Low Stock Alert',
            "{$productName} is running low — only {$currentQty} units left (threshold: {$threshold})",
            ['productName' => $productName, 'currentQty' => $currentQty, 'threshold' => $threshold]
        );
    }

    public function notifyStaffJoined(int $companyId, string $staffName, string $role): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_STAFF_JOINED,
            'New Staff Member',
            "{$staffName} has joined as {$role}",
            ['staffName' => $staffName, 'role' => $role]
        );
    }

    public function notifyCouponUsed(int $companyId, string $couponCode, string $invoiceNo): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_COUPON_USED,
            'Coupon Applied',
            "Coupon {$couponCode} was used on order {$invoiceNo}",
            ['couponCode' => $couponCode, 'invoiceNo' => $invoiceNo]
        );
    }

    public function notifyReturnRequested(int $companyId, string $invoiceNo, string $customerName): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_RETURN_REQUESTED,
            'Return Requested',
            "{$customerName} requested a return for order {$invoiceNo}",
            ['invoiceNo' => $invoiceNo, 'customerName' => $customerName]
        );
    }

    public function notifyStockTransfer(int $companyId, string $fromLocation, string $toLocation, int $itemCount): void
    {
        $this->repository->create(
            $companyId,
            self::TYPE_STOCK_TRANSFER,
            'Stock Transfer Created',
            "{$itemCount} item(s) transferred from {$fromLocation} to {$toLocation}",
            ['fromLocation' => $fromLocation, 'toLocation' => $toLocation, 'itemCount' => $itemCount]
        );
    }
}
