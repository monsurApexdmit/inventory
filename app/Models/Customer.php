<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'customer_type',
        'status',
        'notes',
        'store_credit',
        'type',
        'business_name',
        'licence_bin',
        'customer_group',
        'credit_limit',
        'opening_balance',
        'birthday',
        'tags',
        'sms_opt_in',
        'district',
        'area',
    ];

    /** Auto discount (percent) applied to sales for customers in the VIP group. */
    public const VIP_DISCOUNT_PERCENT = 5;

    protected $hidden = ['password'];

    protected $casts = [
        'store_credit' => 'float',
        'credit_limit' => 'float',
        'opening_balance' => 'float',
        'tags' => 'array',
        'sms_opt_in' => 'boolean',
        'birthday' => 'date:Y-m-d',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function sells(): HasMany
    {
        return $this->hasMany(Sell::class);
    }
}
