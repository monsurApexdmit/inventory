<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFollowUp extends Model
{
    public const UPDATED_AT = null;

    public const OUTCOMES = ['confirmed', 'no_answer', 'call_later', 'wrong_number', 'customer_cancelled', 'other'];
    public const CHANNELS = ['call', 'whatsapp', 'sms', 'other'];
    /** Outcomes that need a note explaining what happened */
    public const NOTE_REQUIRED = ['wrong_number', 'customer_cancelled', 'other'];

    protected $table = 'order_follow_ups';

    protected $fillable = [
        'company_id', 'sell_id', 'user_id', 'staff_name', 'outcome', 'channel', 'note', 'next_follow_up_at',
    ];

    protected $casts = [
        'next_follow_up_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function sell(): BelongsTo
    {
        return $this->belongsTo(Sell::class, 'sell_id');
    }
}
