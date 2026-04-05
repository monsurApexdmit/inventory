<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticableTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Model implements JWTSubject, Authenticatable
{
    use HasFactory, SoftDeletes, AuthenticableTrait;

    protected $fillable = [
        'username',
        'email',
        'password',
        'role_id',
        'address',
    ];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    // ── JWTSubject interface ──────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /** Legacy token carries only user_id — no custom claims. */
    public function getJWTCustomClaims(): array
    {
        return [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function verifyPassword(string $plain): bool
    {
        return Hash::check($plain, $this->password);
    }
}
