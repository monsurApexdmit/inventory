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

class SaasUser extends Model implements JWTSubject, Authenticatable
{
    use HasFactory, SoftDeletes, AuthenticableTrait;

    protected $table = 'saas_users';

    protected $fillable = [
        'company_id',
        'email',
        'full_name',
        'password',
        'role',
        'role_id',
        'status',
        'joined_date',
        'last_login',
        'avatar',
        'uploaded_by',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'joined_date' => 'datetime',
        'last_login'  => 'datetime',
        'password'    => 'hashed',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function staffRole(): BelongsTo
    {
        return $this->belongsTo(StaffRole::class, 'role_id');
    }

    // ── JWTSubject interface ──────────────────────────────────────────────────

    /**
     * The subject identifier stored in the JWT `sub` claim.
     * tymon uses this to look up the user via the guard provider.
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Custom claims merged into the JWT payload.
     * Carries company_id and email so downstream code can read them
     * without a DB round-trip.
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'company_id' => $this->company_id,
            'email'      => $this->email,
            'role'       => $this->role,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function verifyPassword(string $plain): bool
    {
        return Hash::check($plain, $this->password);
    }
}
