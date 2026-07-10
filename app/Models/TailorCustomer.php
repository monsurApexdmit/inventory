<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TailorCustomer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_customers';

    protected $fillable = [
        'company_id', 'name', 'phone', 'address', 'notes',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(TailorMeasurement::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(TailorOrder::class, 'customer_id');
    }
}
