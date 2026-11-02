<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders from social ads:
 *  - campaign_links (shareable order links per post / ad) + campaign_link_clicks (hashed ip/ua only)
 *  - attribution columns on sells (ad source, utm_*, click id, campaign link, landing url, referrer, quick order, duplicate flag)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_links')) {
            Schema::create('campaign_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('channel', 20);                 // facebook|instagram|whatsapp|messenger|tiktok|google|youtube|other
                $table->string('target_type', 20);             // product|collection|store|quick_order
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('code', 20);
                $table->string('utm_source', 100)->nullable();
                $table->string('utm_medium', 100)->nullable();
                $table->string('utm_campaign', 100)->nullable();
                $table->string('utm_content', 100)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedInteger('clicks_count')->default(0);
                $table->timestamp('last_clicked_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'code']);
                $table->index(['company_id', 'channel']);
            });
        }

        if (!Schema::hasTable('campaign_link_clicks')) {
            Schema::create('campaign_link_clicks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_link_id')->constrained('campaign_links')->cascadeOnDelete();
                $table->unsignedBigInteger('company_id');
                $table->timestamp('clicked_at')->useCurrent();
                $table->char('ip_hash', 64);
                $table->char('ua_hash', 64)->nullable();
                $table->string('referrer', 500)->nullable();

                $table->index(['campaign_link_id', 'ip_hash', 'clicked_at'], 'clc_link_ip_time');
                $table->index(['company_id', 'clicked_at'], 'clc_company_time');
            });
        }

        Schema::table('sells', function (Blueprint $table) {
            if (!Schema::hasColumn('sells', 'ad_source')) {
                $table->string('ad_source', 20)->nullable()->after('source');
                $table->string('utm_source', 100)->nullable()->after('ad_source');
                $table->string('utm_medium', 100)->nullable()->after('utm_source');
                $table->string('utm_campaign', 100)->nullable()->after('utm_medium');
                $table->string('utm_content', 100)->nullable()->after('utm_campaign');
                $table->string('utm_term', 100)->nullable()->after('utm_content');
                $table->string('click_id', 255)->nullable()->after('utm_term');
                $table->unsignedBigInteger('campaign_link_id')->nullable()->after('click_id');
                $table->string('landing_url', 500)->nullable()->after('campaign_link_id');
                $table->string('referrer', 500)->nullable()->after('landing_url');
                $table->boolean('is_quick_order')->default(false)->after('referrer');
                $table->boolean('possible_duplicate')->default(false)->after('is_quick_order');

                $table->index(['company_id', 'ad_source'], 'sells_company_ad_source_idx');
                $table->index('campaign_link_id', 'sells_campaign_link_idx');
                $table->foreign('campaign_link_id', 'sells_campaign_link_fk')->references('id')->on('campaign_links')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sells', 'ad_source')) {
            Schema::table('sells', function (Blueprint $table) {
                $table->dropForeign('sells_campaign_link_fk');
                $table->dropIndex('sells_company_ad_source_idx');
                $table->dropIndex('sells_campaign_link_idx');
                $table->dropColumn(['ad_source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_id',
                    'campaign_link_id', 'landing_url', 'referrer', 'is_quick_order', 'possible_duplicate']);
            });
        }
        Schema::dropIfExists('campaign_link_clicks');
        Schema::dropIfExists('campaign_links');
    }
};
