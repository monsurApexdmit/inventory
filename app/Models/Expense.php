<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'expense_category_id',
        'vendor_id',
        'location_id',
        'paid_to',
        'next_due_date',
        'source',
        'salary_payment_id',
        'title',
        'amount',
        'expense_date',
        'payment_method',
        'reference_no',
        'notes',
        'receipt_path',
        'is_recurring',
        'recurring_frequency',
        'recurring_end_date',
        'uploaded_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'is_recurring' => 'boolean',
        'expense_date' => 'date',
        'recurring_end_date' => 'date',
        'next_due_date' => 'date',
    ];

    public const SOURCES = ['manual', 'salary', 'recurring'];

    /** Canonical recurring frequency (Week|Month|Quarter|Year) from any accepted spelling, or null. */
    public static function normalizeFrequency(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'week', 'weekly' => 'Week',
            'month', 'monthly' => 'Month',
            'quarter', 'quarterly' => 'Quarter',
            'year', 'yearly', 'annual', 'annually' => 'Year',
            default => null,
        };
    }

    /** Multiplier converting one occurrence into a monthly-equivalent amount. */
    public static function monthlyFactor(?string $frequency): float
    {
        return match (self::normalizeFrequency($frequency)) {
            'Week' => 52 / 12,
            'Month' => 1.0,
            'Quarter' => 1 / 3,
            'Year' => 1 / 12,
            default => 0.0,
        };
    }

    /** The date one period after $from. */
    public static function advanceDate(\Carbon\CarbonInterface $from, ?string $frequency): \Carbon\Carbon
    {
        $d = \Carbon\Carbon::instance($from)->startOfDay();

        return match (self::normalizeFrequency($frequency)) {
            'Week' => $d->addWeek(),
            'Quarter' => $d->addMonthsNoOverflow(3),
            'Year' => $d->addYearNoOverflow(),
            default => $d->addMonthNoOverflow(),
        };
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
