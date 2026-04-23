<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly int $ticketId,
        public readonly array $message,
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
        return 'support.ticket.message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'ticketId' => $this->ticketId,
            'message' => $this->message,
        ];
    }
}
