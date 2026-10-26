<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffRole extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'staff_roles';

    protected $fillable = ['company_id', 'name', 'description', 'locked', 'sensitive'];

    protected $casts = [
        'locked' => 'boolean',
        'sensitive' => 'array',
    ];

    public const SENSITIVE_KEYS = ['cost', 'discount', 'refund', 'export', 'void'];

    public function isLocked(): bool
    {
        return (bool) $this->locked || strtolower(trim((string) $this->name)) === 'owner';
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'staff_role_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }
}
