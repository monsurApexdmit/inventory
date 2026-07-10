<?php

namespace Tests\Feature\Support;

use App\Events\Notification\NotificationCreated;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use App\Services\Support\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SupportNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Customer $customer;
    private SupportTicketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
        ]);
        $this->service = app(SupportTicketService::class);
    }

    public function test_creating_customer_support_ticket_creates_notification(): void
    {
        Event::fake([NotificationCreated::class]);

        $ticket = $this->service->create($this->company->id, [
            'subject' => 'Package did not arrive',
            'message' => 'Please check the shipment status.',
            'category' => 'shipping',
            'priority' => 'high',
            'customer_name' => $this->customer->name,
            'customer_email' => $this->customer->email,
        ], $this->customer->id);

        $this->assertDatabaseHas('notifications', [
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_SUPPORT_TICKET,
            'title' => 'New Support Ticket',
        ]);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($ticket): bool {
            return $event->companyId === $this->company->id
                && $event->notification['data']['ticketId'] === $ticket['id']
                && $event->notification['type'] === 'support';
        });
    }

    public function test_public_contact_submission_creates_guest_support_ticket_and_notification(): void
    {
        Event::fake([NotificationCreated::class]);

        $response = $this->postJson("/api/store/contact?company_id={$this->company->id}", [
            'name' => 'Guest Contact',
            'email' => 'guest@example.com',
            'subject' => 'Need help before ordering',
            'message' => 'Can you confirm delivery time for this item?',
            'category' => 'product',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customerId', null)
            ->assertJsonPath('data.customerName', 'Guest Contact')
            ->assertJsonPath('data.customerEmail', 'guest@example.com')
            ->assertJsonStructure(['meta' => ['guestAccessToken']]);

        $this->assertDatabaseHas('support_tickets', [
            'company_id' => $this->company->id,
            'customer_id' => null,
            'subject' => 'Need help before ordering',
            'customer_name' => 'Guest Contact',
            'customer_email' => 'guest@example.com',
        ]);

        $this->assertDatabaseHas('notifications', [
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_SUPPORT_TICKET,
            'title' => 'New Support Ticket',
        ]);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return $event->companyId === $this->company->id
                && $event->notification['type'] === 'support'
                && $event->notification['title'] === 'New Support Ticket'
                && $event->unreadCount === 1;
        });
    }

    public function test_guest_can_view_and_reply_to_contact_ticket_with_token(): void
    {
        $contact = $this->postJson("/api/store/contact?company_id={$this->company->id}", [
            'name' => 'Guest Contact',
            'email' => 'guest@example.com',
            'subject' => 'Need help before ordering',
            'message' => 'Can you confirm delivery time for this item?',
            'category' => 'product',
        ])->assertCreated()->json();

        $ticketNumber = $contact['data']['ticketNumber'];
        $token = $contact['meta']['guestAccessToken'];

        $show = $this->getJson("/api/store/support/guest/{$ticketNumber}?company_id={$this->company->id}&token={$token}");
        $show->assertOk()
            ->assertJsonPath('data.ticketNumber', $ticketNumber)
            ->assertJsonPath('data.customerId', null);

        Notification::query()->delete();
        Event::fake([NotificationCreated::class]);

        $reply = $this->postJson("/api/store/support/guest/{$ticketNumber}/reply?company_id={$this->company->id}&token={$token}", [
            'body' => 'Following up on my earlier question.',
        ]);

        $reply->assertOk()
            ->assertJsonPath('data.ticketNumber', $ticketNumber);

        $this->assertDatabaseHas('support_messages', [
            'body' => 'Following up on my earlier question.',
            'sender_type' => 'customer',
            'customer_id' => null,
            'sender_name' => 'Guest Contact',
        ]);

        $this->assertDatabaseHas('notifications', [
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_SUPPORT_MESSAGE,
            'title' => 'Support Reply Received',
        ]);
    }

    public function test_guest_can_authenticate_for_ticket_realtime_channel_with_token(): void
    {
        $contact = $this->postJson("/api/store/contact?company_id={$this->company->id}", [
            'name' => 'Guest Contact',
            'email' => 'guest@example.com',
            'subject' => 'Need help before ordering',
            'message' => 'Can you confirm delivery time for this item?',
            'category' => 'product',
        ])->assertCreated()->json();

        $ticketId = $contact['data']['id'];
        $token = $contact['meta']['guestAccessToken'];

        $response = $this->postJson("/api/store/realtime/auth?company_id={$this->company->id}", [
            'socket_id' => '1234.5678',
            'channel_name' => "private-support.ticket.{$ticketId}",
            'guest_token' => $token,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_customer_reply_creates_notification(): void
    {
        $ticket = $this->service->create($this->company->id, [
            'subject' => 'Need invoice copy',
            'message' => 'Please send invoice.',
            'customer_name' => $this->customer->name,
            'customer_email' => $this->customer->email,
        ], $this->customer->id);

        Notification::query()->delete();
        Event::fake([NotificationCreated::class]);

        $this->service->reply(
            id: $ticket['id'],
            companyId: $this->company->id,
            body: 'Following up on this request.',
            senderType: 'customer',
            customerId: $this->customer->id,
            senderName: $this->customer->name,
        );

        $this->assertDatabaseHas('notifications', [
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_SUPPORT_MESSAGE,
            'title' => 'Support Reply Received',
        ]);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($ticket): bool {
            return $event->companyId === $this->company->id
                && $event->notification['data']['ticketId'] === $ticket['id']
                && $event->notification['type'] === 'support';
        });
    }

    public function test_staff_reply_does_not_create_notification(): void
    {
        $ticket = $this->service->create($this->company->id, [
            'subject' => 'Wrong size item',
            'message' => 'Need exchange support.',
            'customer_name' => $this->customer->name,
            'customer_email' => $this->customer->email,
        ], $this->customer->id);

        Notification::query()->delete();
        Event::fake([NotificationCreated::class]);

        $this->service->reply(
            id: $ticket['id'],
            companyId: $this->company->id,
            body: 'We are checking this for you.',
            senderType: 'staff',
            customerId: null,
            senderName: 'Support Team',
        );

        $this->assertDatabaseMissing('notifications', [
            'company_id' => $this->company->id,
            'type' => NotificationService::TYPE_SUPPORT_MESSAGE,
        ]);

        Event::assertNotDispatched(NotificationCreated::class);
    }
}
