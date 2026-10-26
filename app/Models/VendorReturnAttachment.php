<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorReturnAttachment extends Model
{
    protected $fillable = ['company_id', 'vendor_return_id', 'path', 'name', 'mime', 'size'];
}
