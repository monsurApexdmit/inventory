<?php

namespace App\DTOs\Support;

use App\DTOs\BaseDTO;

class SupportTicketDTO extends BaseDTO
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $companyId,
        public readonly ?int    $customerId,
        public readonly string  $ticketNumber,
        public readonly string  $subject,
        public readonly string  $status,
        public readonly string  $priority,
        public readonly string  $category,
        public readonly ?string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $resolvedAt,
        public readonly string  $createdAt,
        public readonly array   $messages,
        public readonly ?string $updatedAt = null,
        public readonly int     $messageCount = 0,
        public readonly bool    $adminUnread = false,
    ) {}

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'companyId'     => $this->companyId,
            'customerId'    => $this->customerId,
            'ticketNumber'  => $this->ticketNumber,
            'subject'       => $this->subject,
            'status'        => $this->status,
            'priority'      => $this->priority,
            'category'      => $this->category,
            'customerName'  => $this->customerName,
            'customerEmail' => $this->customerEmail,
            'resolvedAt'    => $this->resolvedAt,
            'createdAt'     => $this->createdAt,
            'messages'      => $this->messages,
            'updatedAt'     => $this->updatedAt,
            'messageCount'  => $this->messageCount,
            'adminUnread'   => $this->adminUnread,
        ];
    }
}
