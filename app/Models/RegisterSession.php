<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegisterSession extends Model
{
    protected $table = 'register_sessions';

    protected $fillable = [
        'company_id',
        'register',
        'location_id',
        'session_no',
        'status',
        'opening_cash',
        'opened_by',
        'opened_at',
        'closed_at',
        'expected_cash',
        'counted_cash',
        'variance',
        'close_note',
    ];

    protected $casts = [
        'opening_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'variance' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(RegisterCashMovement::class, 'register_session_id');
    }
}
