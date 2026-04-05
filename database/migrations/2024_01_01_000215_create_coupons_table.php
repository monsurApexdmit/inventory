<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('campaign_name');
            $table->string('code', 100);
            $table->decimal('discount', 15, 4);
            $table->string('type', 50); // percentage, fixed, free_shipping
            $table->timestamp('start_date');
            $table->timestamp('end_date');
            $table->boolean('status')->default(false);
            $table->string('image')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_limit_per_user')->nullable();
            $table->integer('times_used')->default(0);
            $table->decimal('min_order_amount', 15, 2)->default(0);
            $table->decimal('max_discount', 15, 2)->nullable();
            $table->text('applicable_to_categories')->nullable();
            $table->text('applicable_to_products')->nullable();
            $table->boolean('free_shipping')->default(false);
            $table->boolean('stackable')->default(false);
            $table->boolean('auto_apply')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('saas_users')->onDelete('set null');

            // Indexes
            $table->unique(['company_id', 'code'], 'idx_coupon_company_code');
            $table->index('company_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
