<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketPriorityUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly int $ticketId,
        public readonly string $priority,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("support.company.{$this->companyId}"),
            new PrivateChannel("support.ticket.{$this->ticketId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.priority.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'ticketId' => $this->ticketId,
            'priority' => $this->priority,
        ];
    }
}
