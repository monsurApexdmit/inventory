<?php

namespace App\Services\Inventory;

/**
 * One or more order lines cannot be covered by the stock that is left.
 * $shortages: list of {product_id, variant_id, product_name, requested_quantity, available_quantity}.
 */
class InsufficientStockException extends \RuntimeException
{
    public function __construct(public readonly array $shortages)
    {
        $first = $shortages[0] ?? null;
        $message = $first && count($shortages) === 1
            ? "Insufficient stock: only {$first['available_quantity']} {$first['product_name']} left"
            : 'Insufficient stock for ' . count($shortages) . ' items in your order';
        parent::__construct($message, 422);
    }
}
