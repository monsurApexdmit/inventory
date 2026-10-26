<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferDocument extends Model
{
    protected $fillable = [
        'company_id',
        'transfer_no',
        'from_location_id',
        'to_location_id',
        'reason',
        'sent_with',
        'note',
        'status',
        'units',
        'created_by',
        'created_by_name',
        'cancelled_at',
        'reversal_of_id',
        'reversed_by_no',
    ];

    protected $casts = [
        'units'        => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'transfer_document_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }
}
