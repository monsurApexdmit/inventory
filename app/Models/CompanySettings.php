<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySettings extends Model
{
    use HasFactory;

    protected $table = 'company_settings';

    protected $fillable = [
        'company_id',
        'company_name',
        'tax_id',
        'tax_id_type',
        'tax_rate',
        'currency',
        'timezone',
        'language',
        'currency_symbol_position',
        'currency_decimal_separator',
        'currency_thousands_separator',
        'currency_decimal_places',
        'weight_unit',
        'dimension_unit',
        'date_format',
        'time_format',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
