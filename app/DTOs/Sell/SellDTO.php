<?php

namespace App\DTOs\Sell;

use App\DTOs\BaseDTO;

class SellDTO extends BaseDTO
{
    /**
     * @readonly
     */
    public function __construct(
        public int $id,
        public int $companyId,
        public string $invoiceNo,
        public string $orderTime,
        public ?int $customerId,
        public ?array $customer,
        public string $customerName,
        public ?int $shippingAddressId,
        public ?array $shippingAddress,
        public ?string $shippingFullName,
        public ?string $shippingPhone,
        public ?string $shippingEmail,
        public ?string $shippingAddressLine1,
        public ?string $shippingAddressLine2,
        public ?string $shippingCity,
        public ?string $shippingState,
        public ?string $shippingPostalCode,
        public ?string $shippingCountry,
        public ?string $shippingAddressType,
        public string $method,
        public float $amount,
        public float $shippingCost,
        public ?string $shippingMethod,
        public ?string $shippingMethodName,
        public ?int $couponId,
        public ?string $couponCode,
        public float $discount,
        public string $status,
        public bool $stockDeducted,
        public string $paymentStatus,
        public string $fulfillmentStatus,
        public ?string $trackingNumber,
        public ?string $carrier,
        public ?string $shippedAt,
        public ?string $deliveredAt,
        public float $totalCost,
        public float $grossProfit,
        public ?string $notes,
        public ?array $items = null,
        public ?array $shipments = null,
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'companyId' => $this->companyId,
            'invoiceNo' => $this->invoiceNo,
            'orderTime' => $this->orderTime,
            'customerId' => $this->customerId,
            'customer' => $this->customer,
            'customerName' => $this->customerName,
            'shippingAddressId' => $this->shippingAddressId,
            'shippingAddress' => $this->shippingAddress,
            'shippingFullName' => $this->shippingFullName,
            'shippingPhone' => $this->shippingPhone,
            'shippingEmail' => $this->shippingEmail,
            'shippingAddressLine1' => $this->shippingAddressLine1,
            'shippingAddressLine2' => $this->shippingAddressLine2,
            'shippingCity' => $this->shippingCity,
            'shippingState' => $this->shippingState,
            'shippingPostalCode' => $this->shippingPostalCode,
            'shippingCountry' => $this->shippingCountry,
            'shippingAddressType' => $this->shippingAddressType,
            'method' => $this->method,
            'amount' => $this->amount,
            'shippingCost' => $this->shippingCost,
            'shippingMethod' => $this->shippingMethod,
            'shippingMethodName' => $this->shippingMethodName,
            'couponId' => $this->couponId,
            'couponCode' => $this->couponCode,
            'discount' => $this->discount,
            'status' => $this->status,
            'stockDeducted' => $this->stockDeducted,
            'paymentStatus' => $this->paymentStatus,
            'fulfillmentStatus' => $this->fulfillmentStatus,
            'trackingNumber' => $this->trackingNumber,
            'carrier' => $this->carrier,
            'shippedAt' => $this->shippedAt,
            'deliveredAt' => $this->deliveredAt,
            'totalCost' => $this->totalCost,
            'grossProfit' => $this->grossProfit,
            'notes' => $this->notes,
            'items' => $this->items,
            'shipments' => $this->shipments,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
