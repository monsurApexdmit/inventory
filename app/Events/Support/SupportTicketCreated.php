<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly array $ticket,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("support.company.{$this->companyId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.created';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket' => $this->ticket,
        ];
    }
}
