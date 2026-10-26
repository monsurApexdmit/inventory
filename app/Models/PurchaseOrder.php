<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vendor_id',
        'location_id',
        'po_number',
        'status',
        'expected_date',
        'notes',
        'total_amount',
        'paid_amount',
        'due_amount',
        'payment_status',
        'discount_type',
        'discount_value',
        'tax_rate',
        'shipping_cost',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'due_amount' => 'float',
        'expected_date' => 'date:Y-m-d',
        'discount_value' => 'float',
        'tax_rate' => 'float',
        'shipping_cost' => 'float',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class)->orderByDesc('received_at')->orderByDesc('id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(DuePayment::class, 'payable')->orderByDesc('payment_date')->orderByDesc('id');
    }

    /**
     * total = subtotal - discount + tax(on subtotal - discount) + shipping
     *
     * @return array{subtotal: float, discountAmount: float, taxAmount: float, shippingCost: float, total: float}
     */
    public static function computeTotals(float $subtotal, string $discountType, float $discountValue, float $taxRate, float $shipping): array
    {
        $subtotal = round($subtotal, 2);
        $discount = $discountType === 'percent'
            ? $subtotal * min(max($discountValue, 0), 100) / 100
            : min(max($discountValue, 0), $subtotal);
        $discount = round($discount, 2);
        $tax = round(($subtotal - $discount) * max($taxRate, 0) / 100, 2);
        $shipping = round(max($shipping, 0), 2);

        return [
            'subtotal' => $subtotal,
            'discountAmount' => $discount,
            'taxAmount' => $tax,
            'shippingCost' => $shipping,
            'total' => round($subtotal - $discount + $tax + $shipping, 2),
        ];
    }

    /** "Name ×qty, Name ×qty +N more" over the loaded items. */
    public function itemsSummary(): string
    {
        $lines = $this->items->map(fn($i) => ($i->product?->name ?? 'Item') . ' ×' . $i->quantity_ordered)->values();
        if ($lines->isEmpty()) {
            return 'No items';
        }
        $text = $lines->take(2)->implode(', ');
        return $lines->count() > 2 ? $text . ' +' . ($lines->count() - 2) . ' more' : $text;
    }

    /** Past expected date with money still owed on a received/partially received order. */
    public function isOverdue(): bool
    {
        return in_array($this->status, ['partial', 'received'], true)
            && (float) $this->due_amount > 0
            && $this->expected_date !== null
            && $this->expected_date->lt(now()->startOfDay());
    }

    public function recalculate(): void
    {
        $this->paid_amount = $this->payments()->where('direction', 'out')->sum('amount');
        $this->due_amount  = max(0, $this->total_amount - $this->paid_amount);
        if ($this->paid_amount <= 0) {
            $this->payment_status = 'pending';
        } elseif ($this->due_amount <= 0) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partially_paid';
        }
        $this->save();
    }
}
