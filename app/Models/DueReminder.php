<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per customer-dues reminder action (SMS / WhatsApp / Copy). Nothing is sent from here. */
class DueReminder extends Model
{
    protected $table = 'due_reminders';

    protected $fillable = ['company_id', 'customer_id', 'channel', 'amount', 'message', 'recorded_by'];

    protected $casts = ['amount' => 'float'];
}
