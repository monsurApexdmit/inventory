<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends Model
{
    use HasFactory;

    protected $table = 'role_permissions';

    protected $fillable = [
        'role_id',
        'permission_id',
        'read',
        'write',
        'create',
        'edit',
        'delete',
    ];

    protected $casts = [
        'read' => 'boolean',
        'write' => 'boolean',
        'create' => 'boolean',
        'edit' => 'boolean',
        'delete' => 'boolean',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(StaffRole::class, 'role_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
