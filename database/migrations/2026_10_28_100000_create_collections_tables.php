<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collections')) {
            Schema::create('collections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('slug', 191);
                $table->text('description')->nullable();
                $table->string('image', 255)->nullable();
                $table->string('type', 16)->default('manual');      // manual | automatic
                $table->string('match', 8)->default('all');         // all | any
                $table->json('rules')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                // manual | best_selling | price_asc | price_desc | newest | name
                $table->string('product_sort', 20)->default('manual');
                $table->boolean('is_published')->default(false);
                $table->boolean('show_on_homepage')->default(false);
                $table->string('seo_title', 255)->nullable();
                $table->string('seo_description', 500)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'slug']);
                $table->index(['company_id', 'is_published', 'sort_order']);
            });
        }

        if (! Schema::hasTable('collection_product')) {
            Schema::create('collection_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['collection_id', 'product_id']);
                $table->index(['collection_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('collections');
    }
};
