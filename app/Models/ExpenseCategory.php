<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'color',
        'monthly_budget',
        'type',
        'status',
    ];

    protected $casts = [
        'monthly_budget' => 'float',
        'status' => 'boolean',
    ];

    public const TYPES = ['Operating cost', 'Cost of goods', 'Owner drawing', 'Tax / fees'];

    public const SYSTEM_NAME = 'Salaries';

    /** The Salaries category is created by payroll and cannot be renamed, deactivated or deleted. */
    public function isSystem(): bool
    {
        return $this->name === self::SYSTEM_NAME;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }
}
