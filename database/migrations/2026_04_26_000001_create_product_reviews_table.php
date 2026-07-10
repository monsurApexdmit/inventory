<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->tinyInteger('rating');
            $table->text('comment');
            $table->boolean('verified_purchase')->default(false);
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->text('reply_body')->nullable();
            $table->string('reply_author_name', 150)->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('saas_users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'customer_id']);
            $table->index(['company_id', 'product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
