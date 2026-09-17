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
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
