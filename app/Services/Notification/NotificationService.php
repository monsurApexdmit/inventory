<?php

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationDTO;
use App\DTOs\Notification\NotificationMapper;
use App\Events\Notification\NotificationCreated;
use App\Events\Notification\NotificationsDeleted;
use App\Events\Notification\NotificationsMarkedAllRead;
use App\Events\Notification\NotificationUpdated;
use App\Models\Notification;
use App\Repositories\Contracts\INotificationRepository;
use Illuminate\Support\Facades\Log;
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
    public const TYPE_SUPPORT_TICKET       = 'support_ticket';
    public const TYPE_SUPPORT_MESSAGE      = 'support_message';
    public const TYPE_PRODUCT_REVIEW       = 'product_review';

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
        $updated = $this->repository->findById($id, $companyId);

        if ($updated) {
            $this->dispatchBroadcast(new NotificationUpdated(
                companyId: $companyId,
                action: 'read',
                notification: $this->mapper->toDTO($updated)->toArray(),
                unreadCount: $this->repository->countUnread($companyId),
            ));
        }
    }

    public function markAsUnread(int $id, int $companyId): void
    {
        $notification = $this->repository->findById($id, $companyId);
        if (!$notification) {
            throw new HttpException(404, 'Notification not found');
        }
        $this->repository->markAsUnread($id, $companyId);
        $updated = $this->repository->findById($id, $companyId);

        if ($updated) {
            $this->dispatchBroadcast(new NotificationUpdated(
                companyId: $companyId,
                action: 'unread',
                notification: $this->mapper->toDTO($updated)->toArray(),
                unreadCount: $this->repository->countUnread($companyId),
            ));
        }
    }

    public function markAllAsRead(int $companyId): void
    {
        $unreadIds = $this->repository->findUnreadIdsByCompany($companyId);
        $this->repository->markAllAsRead($companyId);

        if ($unreadIds !== []) {
            $this->dispatchBroadcast(new NotificationsMarkedAllRead(
                companyId: $companyId,
                notificationIds: $unreadIds,
                unreadCount: $this->repository->countUnread($companyId),
            ));
        }
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
        $this->dispatchBroadcast(new NotificationsDeleted(
            companyId: $companyId,
            action: 'delete',
            notificationIds: [$id],
            unreadCount: $this->repository->countUnread($companyId),
        ));
    }

    public function bulkDelete(int $companyId, array $ids): void
    {
        $existingIds = $this->repository->findExistingIdsByCompany($companyId, $ids);
        $this->repository->bulkDelete($companyId, $ids);

        if ($existingIds !== []) {
            $this->dispatchBroadcast(new NotificationsDeleted(
                companyId: $companyId,
                action: 'bulk_delete',
                notificationIds: $existingIds,
                unreadCount: $this->repository->countUnread($companyId),
            ));
        }
    }

    public function deleteOld(int $companyId, int $keepDays = 30): array
    {
        $count = $this->repository->deleteOld($companyId, $keepDays);
        return ['deleted' => $count];
    }

    // ─── Creation helpers called by other services ───────────────────────────

    public function notifyOrderPlaced(int $companyId, string $invoiceNo, string $customerName, float $amount): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_ORDER_PLACED,
            'New Order Received',
            "Order {$invoiceNo} placed by {$customerName} for $" . number_format($amount, 2),
            ['invoiceNo' => $invoiceNo, 'customerName' => $customerName, 'amount' => $amount]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyOrderStatusChanged(int $companyId, string $invoiceNo, string $oldStatus, string $newStatus): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_ORDER_STATUS_CHANGED,
            'Order Status Updated',
            "Order {$invoiceNo} changed from {$oldStatus} to {$newStatus}",
            ['invoiceNo' => $invoiceNo, 'oldStatus' => $oldStatus, 'newStatus' => $newStatus]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyLowStock(int $companyId, string $productName, int $currentQty, int $threshold): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_LOW_STOCK,
            'Low Stock Alert',
            "{$productName} is running low — only {$currentQty} units left (threshold: {$threshold})",
            ['productName' => $productName, 'currentQty' => $currentQty, 'threshold' => $threshold]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyStaffJoined(int $companyId, string $staffName, string $role): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_STAFF_JOINED,
            'New Staff Member',
            "{$staffName} has joined as {$role}",
            ['staffName' => $staffName, 'role' => $role]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyCouponUsed(int $companyId, string $couponCode, string $invoiceNo): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_COUPON_USED,
            'Coupon Applied',
            "Coupon {$couponCode} was used on order {$invoiceNo}",
            ['couponCode' => $couponCode, 'invoiceNo' => $invoiceNo]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyReturnRequested(int $companyId, string $invoiceNo, string $customerName): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_RETURN_REQUESTED,
            'Return Requested',
            "{$customerName} requested a return for order {$invoiceNo}",
            ['invoiceNo' => $invoiceNo, 'customerName' => $customerName]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyStockTransfer(int $companyId, string $fromLocation, string $toLocation, int $itemCount): void
    {
        $notification = $this->repository->create(
            $companyId,
            self::TYPE_STOCK_TRANSFER,
            'Stock Transfer Created',
            "{$itemCount} item(s) transferred from {$fromLocation} to {$toLocation}",
            ['fromLocation' => $fromLocation, 'toLocation' => $toLocation, 'itemCount' => $itemCount]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifySupportTicketCreated(int $companyId, string $ticketNumber, string $subject, ?string $customerName = null, ?int $ticketId = null): void
    {
        $name = $customerName ?: 'A customer';

        $notification = $this->repository->create(
            $companyId,
            self::TYPE_SUPPORT_TICKET,
            'New Support Ticket',
            "{$name} opened ticket {$ticketNumber}: {$subject}",
            [
                'ticketId' => $ticketId,
                'ticketNumber' => $ticketNumber,
                'subject' => $subject,
                'customerName' => $customerName,
            ]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifySupportMessageReceived(int $companyId, string $ticketNumber, string $subject, ?string $senderName = null, ?int $ticketId = null): void
    {
        $name = $senderName ?: 'A customer';

        $notification = $this->repository->create(
            $companyId,
            self::TYPE_SUPPORT_MESSAGE,
            'Support Reply Received',
            "{$name} sent a new message on ticket {$ticketNumber}: {$subject}",
            [
                'ticketId' => $ticketId,
                'ticketNumber' => $ticketNumber,
                'subject' => $subject,
                'senderName' => $senderName,
            ]
        );

        $this->broadcastCreatedNotification($notification);
    }

    public function notifyProductReviewReceived(
        int $companyId,
        int $productId,
        int $reviewId,
        string $productName,
        string $customerName,
        int $rating
    ): void {
        $name = $customerName ?: 'A customer';

        $notification = $this->repository->create(
            $companyId,
            self::TYPE_PRODUCT_REVIEW,
            'New Product Review',
            "{$name} left a {$rating}-star review for {$productName}",
            [
                'productId' => $productId,
                'reviewId' => $reviewId,
                'productName' => $productName,
                'customerName' => $customerName,
                'rating' => $rating,
            ]
        );

        $this->broadcastCreatedNotification($notification);
    }

    private function broadcastCreatedNotification(Notification $notification): void
    {
        $dto = $this->mapper->toDTO($notification);
        $this->dispatchBroadcast(new NotificationCreated(
            companyId: $dto->companyId,
            notification: $dto->toArray(),
            unreadCount: $this->repository->countUnread($dto->companyId),
        ));
    }

    private function dispatchBroadcast(object $event): void
    {
        if (config('broadcasting.default') === 'reverb' && !class_exists(\Pusher\Pusher::class)) {
            Log::warning('Skipping notification broadcast because Pusher PHP SDK is missing.');
            return;
        }

        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Notification broadcast failed: '.$e->getMessage());
        }
    }
}
