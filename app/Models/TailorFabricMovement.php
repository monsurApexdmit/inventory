<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TailorFabricMovement extends Model
{
    protected $table = 'tailor_fabric_movements';

    protected $fillable = [
        'company_id', 'fabric_id', 'type', 'quantity', 'balance_after', 'unit_cost',
        'vendor_id', 'reference_type', 'reference_id', 'note', 'created_by',
    ];

    protected $casts = [
        'quantity'      => 'float',
        'balance_after' => 'float',
        'unit_cost'     => 'float',
    ];

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(TailorFabric::class, 'fabric_id');
    }

    /** Record a stock change; $fabric->stock_quantity must already hold the new balance. */
    public static function log(TailorFabric $fabric, string $type, float $qty, ?string $note = null, array $extra = []): self
    {
        $uid = request()?->attributes?->get('auth_user_id');

        return static::create(array_merge([
            'company_id'    => $fabric->company_id,
            'fabric_id'     => $fabric->id,
            'type'          => $type,
            'quantity'      => $qty,
            'balance_after' => (float) $fabric->stock_quantity,
            'note'          => $note,
            'created_by'    => $uid ? (int) $uid : null,
        ], $extra));
    }
}
