<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One received courier webhook delivery (dedupe log: provider + dedupe_key is unique). */
class CourierWebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'courier_account_id', 'provider', 'dedupe_key', 'shipment_id', 'result', 'payload'];

    protected $casts = ['payload' => 'array'];
}
