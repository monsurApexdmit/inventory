<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierAccount extends Model
{
    protected $fillable = ['company_id', 'provider', 'account_id', 'api_key', 'is_active'];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key'   => 'encrypted',
        'is_active' => 'boolean',
    ];
}
