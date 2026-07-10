<?php

namespace Tests\Feature\Notification;

use App\Events\Notification\NotificationCreated;
use App\Events\Notification\NotificationsDeleted;
use App\Events\Notification\NotificationsMarkedAllRead;
use App\Events\Notification\NotificationUpdated;
use App\Models\Company;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->service = app(NotificationService::class);
    }

    public function test_notify_order_placed_broadcasts_created_event(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->service->notifyOrderPlaced($this->company->id, 'INV-1001', 'John Doe', 125.50);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return $event->companyId === $this->company->id
                && $event->notification['title'] === 'New Order Received'
                && $event->notification['data']['invoiceNo'] === 'INV-1001'
                && $event->unreadCount === 1;
        });
    }

    public function test_notify_product_review_broadcasts_created_event(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->service->notifyProductReviewReceived(
            $this->company->id,
            55,
            78,
            'Travel Bag',
            'Review Customer',
            5,
        );

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return $event->companyId === $this->company->id
                && $event->notification['type'] === 'review'
                && $event->notification['title'] === 'New Product Review'
                && $event->notification['actionUrl'] === '/dashboard/products/55/reviews'
                && $event->notification['data']['reviewId'] === 78
                && $event->unreadCount === 1;
        });
    }

    public function test_mark_as_read_broadcasts_updated_event(): void
    {
        $notification = Notification::create([
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_ORDER_PLACED,
            'title' => 'New Order Received',
            'message' => 'Order INV-1002 placed',
            'data' => ['invoiceNo' => 'INV-1002'],
        ]);

        Event::fake([NotificationUpdated::class]);

        $this->service->markAsRead($notification->id, $this->company->id);

        Event::assertDispatched(NotificationUpdated::class, function (NotificationUpdated $event) use ($notification): bool {
            return $event->companyId === $this->company->id
                && $event->action === 'read'
                && $event->notification['id'] === $notification->id
                && $event->notification['isRead'] === true
                && $event->unreadCount === 0;
        });
    }

    public function test_mark_all_as_read_broadcasts_bulk_read_event(): void
    {
        $first = Notification::create([
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_LOW_STOCK,
            'title' => 'Low Stock Alert',
            'message' => 'Item A is low',
        ]);

        $second = Notification::create([
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_STOCK_TRANSFER,
            'title' => 'Stock Transfer Created',
            'message' => 'Transfer created',
        ]);

        Event::fake([NotificationsMarkedAllRead::class]);

        $this->service->markAllAsRead($this->company->id);

        Event::assertDispatched(NotificationsMarkedAllRead::class, function (NotificationsMarkedAllRead $event) use ($first, $second): bool {
            $ids = $event->notificationIds;
            sort($ids);

            return $event->companyId === $this->company->id
                && $ids === [$first->id, $second->id]
                && $event->unreadCount === 0;
        });
    }

    public function test_delete_broadcasts_deleted_event(): void
    {
        $notification = Notification::create([
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_STAFF_JOINED,
            'title' => 'New Staff Member',
            'message' => 'Jane joined as Manager',
        ]);

        Event::fake([NotificationsDeleted::class]);

        $this->service->delete($notification->id, $this->company->id);

        Event::assertDispatched(NotificationsDeleted::class, function (NotificationsDeleted $event) use ($notification): bool {
            return $event->companyId === $this->company->id
                && $event->action === 'delete'
                && $event->notificationIds === [$notification->id];
        });
    }
}
