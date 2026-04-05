<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'industry',
        'phone',
        'email',
        'website',
        'country',
        'address',
        'city',
        'state',
        'zip_code',
        'logo',
        'business_type',
        'tax_id',
        'currency',
        'timezone',
        'language',
        'status',
        'description',
        'uploaded_by',
    ];

    public function saasUsers(): HasMany
    {
        return $this->hasMany(SaasUser::class, 'company_id');
    }

    public function settings(): HasOne
    {
        return $this->hasOne(CompanySettings::class, 'company_id');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'company_id');
    }

    public function staffRoles(): HasMany
    {
        return $this->hasMany(StaffRole::class, 'company_id');
    }

    public function billingContact(): HasOne
    {
        return $this->hasOne(BillingContact::class, 'company_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'company_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'company_id');
    }
}
