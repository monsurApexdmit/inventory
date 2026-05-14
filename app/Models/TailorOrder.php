<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TailorOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_orders';

    protected $fillable = [
        'company_id', 'order_number', 'tracking_token', 'customer_id',
        'order_date', 'delivery_date',
        'stitching_charge', 'extra_charge', 'discount',
        'total_amount', 'paid_amount', 'due_amount',
        'payment_status', 'order_status', 'notes',
    ];

    protected $attributes = [
        'order_status'   => 'pending',
        'payment_status' => 'unpaid',
        'paid_amount'    => 0,
        'due_amount'     => 0,
        'total_amount'   => 0,
    ];

    protected $casts = [
        'order_date'       => 'date',
        'delivery_date'    => 'date',
        'stitching_charge' => 'float',
        'extra_charge'     => 'float',
        'discount'         => 'float',
        'total_amount'     => 'float',
        'paid_amount'      => 'float',
        'due_amount'       => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber($order->company_id);
            }
            if (empty($order->tracking_token)) {
                do {
                    $token = strtoupper(Str::random(12));
                } while (static::where('tracking_token', $token)->exists());
                $order->tracking_token = $token;
            }
        });
    }

    public static function generateOrderNumber(int $companyId): string
    {
        $date  = now()->format('Ymd');
        $count = static::where('company_id', $companyId)
                       ->whereDate('created_at', today())
                       ->count() + 1;
        return 'TO-' . $date . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    public function recalculate(): void
    {
        $fabricCost        = $this->items->sum(fn($i) => $i->fabric_quantity * $i->fabric_unit_price);
        $this->total_amount = $fabricCost + $this->stitching_charge + $this->extra_charge - $this->discount;
        $this->due_amount   = max(0, $this->total_amount - $this->paid_amount);

        if ($this->paid_amount <= 0) {
            $this->payment_status = 'unpaid';
        } elseif ($this->due_amount <= 0) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
        }
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TailorCustomer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TailorOrderItem::class, 'order_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TailorAssignment::class, 'order_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TailorPayment::class, 'order_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(TailorStatusLog::class, 'order_id')->orderBy('created_at');
    }
}
