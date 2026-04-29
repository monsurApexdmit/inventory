<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->unique(['company_id', 'slug'], 'products_company_slug_unique');
        });

        Product::withTrashed()
            ->select(['id', 'company_id', 'name'])
            ->orderBy('company_id')
            ->orderBy('id')
            ->chunkById(100, function ($products) {
                $usedSlugs = [];

                foreach ($products as $product) {
                    $companyId = (int) $product->company_id;
                    $baseSlug = Str::slug($product->name ?: 'product');

                    if ($baseSlug === '') {
                        $baseSlug = 'product';
                    }

                    if (!isset($usedSlugs[$companyId])) {
                        $usedSlugs[$companyId] = DB::table('products')
                            ->where('company_id', $companyId)
                            ->whereNotNull('slug')
                            ->where('id', '<>', $product->id)
                            ->pluck('slug')
                            ->all();
                    }

                    $slug = $baseSlug;
                    $suffix = 2;

                    while (in_array($slug, $usedSlugs[$companyId], true)) {
                        $slug = "{$baseSlug}-{$suffix}";
                        $suffix++;
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['slug' => $slug]);

                    $usedSlugs[$companyId][] = $slug;
                }
            });

        DB::statement('ALTER TABLE products MODIFY slug VARCHAR(255) NOT NULL');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_company_slug_unique');
            $table->dropColumn('slug');
        });
    }
};
