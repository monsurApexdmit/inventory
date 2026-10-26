<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded so a partially applied run (MySQL DDL is not transactional) can be re-run.
        if (! Schema::hasColumn('content_pages', 'type')) {
            Schema::table('content_pages', fn (Blueprint $t) => $t->string('type', 20)->default('custom')->after('summary'));
        }
        if (! Schema::hasColumn('content_pages', 'place')) {
            Schema::table('content_pages', fn (Blueprint $t) => $t->string('place', 20)->default('footer')->after('type'));
        }
        if (! Schema::hasColumn('content_pages', 'body')) {
            Schema::table('content_pages', fn (Blueprint $t) => $t->longText('body')->nullable()->after('place'));
        }

        // Backfill type from the stored template so existing pages keep their layout.
        foreach (['about', 'faq', 'policy'] as $tpl) {
            DB::table('content_pages')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(content, '$.template')) = ?", [$tpl])
                ->update(['type' => $tpl]);
        }
    }

    public function down(): void
    {
        Schema::table('content_pages', function (Blueprint $table) {
            $table->dropColumn(['type', 'place', 'body']);
        });
    }
};
