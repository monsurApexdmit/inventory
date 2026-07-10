<?php

namespace App\DTOs\Notification;

use App\DTOs\BaseDTO;

class NotificationDTO extends BaseDTO
{
    // Maps internal types → frontend NotificationType
    private const TYPE_MAP = [
        'order_placed'          => 'order',
        'order_status_changed'  => 'order',
        'coupon_used'           => 'order',
        'low_stock'             => 'stock_alert',
        'stock_transfer'        => 'stock_alert',
        'staff_joined'          => 'system',
        'return_requested'      => 'payment',
        'support_ticket'        => 'support',
        'support_message'       => 'support',
        'product_review'        => 'review',
    ];

    // Maps internal types → frontend NotificationPriority
    private const PRIORITY_MAP = [
        'order_placed'          => 'medium',
        'order_status_changed'  => 'medium',
        'coupon_used'           => 'low',
        'low_stock'             => 'high',
        'stock_transfer'        => 'low',
        'staff_joined'          => 'low',
        'return_requested'      => 'high',
        'support_ticket'        => 'medium',
        'support_message'       => 'medium',
        'product_review'        => 'medium',
    ];

    public function __construct(
        public readonly int     $id,
        public readonly int     $companyId,
        public readonly string  $type,
        public readonly string  $title,
        public readonly string  $message,
        public readonly ?array  $data,
        public readonly bool    $isRead,
        public readonly ?string $readAt,
        public readonly string  $createdAt,
    ) {}

    public function toArray(): array
    {
        $frontendType = self::TYPE_MAP[$this->type] ?? 'system';
        $priority     = self::PRIORITY_MAP[$this->type] ?? 'low';
        $actionUrl    = $this->resolveActionUrl();

        return [
            'id'        => $this->id,
            'companyId' => $this->companyId,
            'type'      => $frontendType,
            'title'     => $this->title,
            'message'   => $this->message,
            'priority'  => $priority,
            'actionUrl' => $actionUrl,
            'data'      => $this->data,
            'isRead'    => $this->isRead,
            'readAt'    => $this->readAt,
            'createdAt' => $this->createdAt,
        ];
    }

    private function resolveActionUrl(): ?string
    {
        return match ($this->type) {
            'order_placed', 'order_status_changed', 'coupon_used', 'return_requested'
                => isset($this->data['invoiceNo'])
                    ? "/dashboard/orders?search={$this->data['invoiceNo']}"
                    : '/dashboard/orders',
            'low_stock', 'stock_transfer'
                => '/dashboard/inventory',
            'staff_joined'
                => '/dashboard/staff',
            'support_ticket', 'support_message'
                => isset($this->data['ticketId'])
                    ? "/dashboard/support?ticket={$this->data['ticketId']}"
                    : '/dashboard/support',
            'product_review'
                => isset($this->data['productId'])
                    ? "/dashboard/products/{$this->data['productId']}/reviews"
                    : '/dashboard/products',
            default => null,
        };
    }
}
