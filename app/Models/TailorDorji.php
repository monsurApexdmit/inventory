<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TailorDorji extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_dorjis';

    protected $fillable = [
        'company_id', 'name', 'phone', 'address',
        'speciality', 'commission_type', 'commission_value',
        'status', 'notes',
    ];

    protected $casts = [
        'speciality'       => 'array',
        'commission_value' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TailorAssignment::class, 'dorji_id');
    }
}
