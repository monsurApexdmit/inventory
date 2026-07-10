<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    use HasFactory;

    protected $table = 'product_reviews';

    protected $fillable = [
        'company_id',
        'product_id',
        'customer_id',
        'rating',
        'comment',
        'verified_purchase',
        'customer_name',
        'customer_email',
        'reply_body',
        'reply_author_name',
        'replied_by',
        'replied_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'verified_purchase' => 'boolean',
        'replied_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(SaasUser::class, 'replied_by');
    }
}
