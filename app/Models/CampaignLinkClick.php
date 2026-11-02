<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One (deduplicated) click on a campaign link. Only hashes of IP / user agent are stored. */
class CampaignLinkClick extends Model
{
    public $timestamps = false;

    protected $table = 'campaign_link_clicks';

    protected $fillable = ['campaign_link_id', 'company_id', 'clicked_at', 'ip_hash', 'ua_hash', 'referrer'];

    protected $casts = ['clicked_at' => 'datetime'];
}
