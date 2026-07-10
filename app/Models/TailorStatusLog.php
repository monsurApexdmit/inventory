<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorStatusLog extends Model
{
    use HasFactory;

    protected $table = 'tailor_status_logs';

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'changed_by', 'note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TailorOrder::class, 'order_id');
    }
}
