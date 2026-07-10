<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TailorAssignment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tailor_assignments';

    protected $fillable = [
        'company_id', 'order_id', 'dorji_id',
        'assigned_date', 'expected_completion',
        'dorji_charge', 'work_status', 'admin_notes',
    ];

    protected $casts = [
        'assigned_date'       => 'date',
        'expected_completion' => 'date',
        'dorji_charge'        => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TailorOrder::class, 'order_id');
    }

    public function dorji(): BelongsTo
    {
        return $this->belongsTo(TailorDorji::class, 'dorji_id');
    }
}
