<?php

namespace App\DTOs\Support;

use App\DTOs\BaseDTO;

class SupportMessageDTO extends BaseDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $ticketId,
        public readonly ?int    $customerId,
        public readonly string  $body,
        public readonly string  $senderType,
        public readonly ?string $senderName,
        public readonly string  $createdAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'ticketId'   => $this->ticketId,
            'customerId' => $this->customerId,
            'body'       => $this->body,
            'senderType' => $this->senderType,
            'senderName' => $this->senderName,
            'createdAt'  => $this->createdAt,
        ];
    }
}
