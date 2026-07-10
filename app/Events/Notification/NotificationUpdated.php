<?php

namespace App\Events\Notification;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly string $action,
        public readonly array $notification,
        public readonly int $unreadCount,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("notifications.company.{$this->companyId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'notification' => $this->notification,
            'unreadCount' => $this->unreadCount,
        ];
    }
}
