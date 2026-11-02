<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A shareable order link for a social post / ad (Marketing → Ad order links). */
class CampaignLink extends Model
{
    use SoftDeletes;

    public const CHANNELS = ['facebook', 'instagram', 'whatsapp', 'messenger', 'tiktok', 'google', 'youtube', 'other'];
    public const TARGET_TYPES = ['product', 'collection', 'store', 'quick_order'];

    protected $table = 'campaign_links';

    protected $fillable = [
        'company_id', 'name', 'channel', 'target_type', 'target_id', 'code',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'notes',
        'is_active', 'created_by', 'clicks_count', 'last_clicked_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'clicks_count' => 'integer',
        'target_id' => 'integer',
        'last_clicked_at' => 'datetime',
    ];

    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignLinkClick::class, 'campaign_link_id');
    }

    public function sells(): HasMany
    {
        return $this->hasMany(Sell::class, 'campaign_link_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(SaasUser::class, 'created_by');
    }
}
