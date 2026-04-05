<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Setting extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'settings';

    protected $fillable = [
        'company_id',
        'general_settings',
        'tax_settings',
        'shipping_settings',
        'payment_settings',
        'business_settings',
        'regional_settings',
        'notification_settings',
        'store_hours',
        'logo_url',
        'banner_url',
        'uploaded_by',
    ];

    protected $casts = [
        'general_settings' => 'array',
        'tax_settings' => 'array',
        'shipping_settings' => 'array',
        'payment_settings' => 'array',
        'business_settings' => 'array',
        'regional_settings' => 'array',
        'notification_settings' => 'array',
        'store_hours' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(SaasUser::class, 'uploaded_by');
    }
}
