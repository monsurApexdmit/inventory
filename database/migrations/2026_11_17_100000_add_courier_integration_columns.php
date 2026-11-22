<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Courier API integration (Pathao + Steadfast): credentials/token/webhook columns on courier_accounts,
 * booking/sync columns on order_shipments, and a webhook dedupe log. Idempotent (safe to re-run).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('courier_accounts', 'credentials')) {
                $table->text('credentials')->nullable()->after('api_key');
            }
            if (!Schema::hasColumn('courier_accounts', 'environment')) {
                $table->string('environment', 10)->default('live')->after('credentials');
            }
            if (!Schema::hasColumn('courier_accounts', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('courier_accounts', 'settings')) {
                $table->json('settings')->nullable()->after('is_default');
            }
            if (!Schema::hasColumn('courier_accounts', 'webhook_token')) {
                $table->string('webhook_token', 64)->nullable()->after('settings');
            }
            if (!Schema::hasColumn('courier_accounts', 'token_cache')) {
                $table->text('token_cache')->nullable()->after('webhook_token');
            }
            if (!Schema::hasColumn('courier_accounts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('token_cache');
            }
            if (!Schema::hasColumn('courier_accounts', 'last_checked_at')) {
                $table->timestamp('last_checked_at')->nullable()->after('token_expires_at');
            }
            if (!Schema::hasColumn('courier_accounts', 'last_error')) {
                $table->string('last_error', 500)->nullable()->after('last_checked_at');
            }
        });

        if (!$this->hasIndex('courier_accounts', 'courier_accounts_webhook_token_index')) {
            Schema::table('courier_accounts', fn (Blueprint $t) => $t->index('webhook_token', 'courier_accounts_webhook_token_index'));
        }

        // Existing rows: give each a webhook token, and move an obvious legacy key into credentials
        foreach (DB::table('courier_accounts')->get() as $row) {
            $patch = [];
            if (empty($row->webhook_token)) {
                $patch['webhook_token'] = Str::random(40);
            }
            if (empty($row->credentials) && !empty($row->api_key)) {
                try {
                    $key = Crypt::decryptString($row->api_key);
                    $provider = strtolower(trim((string) $row->provider));
                    if ($provider === 'steadfast' && $key !== '') {
                        $patch['credentials'] = Crypt::encryptString(json_encode(['apiKey' => $key]));
                    }
                } catch (\Throwable $e) {
                    // unreadable legacy key: leave as is
                }
            }
            if ($patch) {
                DB::table('courier_accounts')->where('id', $row->id)->update($patch);
            }
        }

        Schema::table('order_shipments', function (Blueprint $table) {
            $cols = [
                'courier_account_id' => fn () => $table->unsignedBigInteger('courier_account_id')->nullable()->after('carrier'),
                'courier_provider' => fn () => $table->string('courier_provider', 30)->nullable()->after('courier_account_id'),
                'consignment_id' => fn () => $table->string('consignment_id', 100)->nullable()->after('courier_provider'),
                'courier_state' => fn () => $table->string('courier_state', 30)->nullable()->after('consignment_id'),
                'courier_status' => fn () => $table->string('courier_status', 100)->nullable()->after('courier_state'),
                'courier_status_at' => fn () => $table->timestamp('courier_status_at')->nullable()->after('courier_status'),
                'courier_payload' => fn () => $table->json('courier_payload')->nullable(),
                'tracking_url' => fn () => $table->string('tracking_url', 500)->nullable(),
                'delivery_fee' => fn () => $table->decimal('delivery_fee', 12, 2)->nullable(),
                'courier_cod_collected' => fn () => $table->decimal('courier_cod_collected', 12, 2)->nullable(),
                'courier_store_id' => fn () => $table->string('courier_store_id', 50)->nullable(),
                'pathao_city_id' => fn () => $table->unsignedInteger('pathao_city_id')->nullable(),
                'pathao_city_name' => fn () => $table->string('pathao_city_name', 120)->nullable(),
                'pathao_zone_id' => fn () => $table->unsignedInteger('pathao_zone_id')->nullable(),
                'pathao_zone_name' => fn () => $table->string('pathao_zone_name', 120)->nullable(),
                'pathao_area_id' => fn () => $table->unsignedInteger('pathao_area_id')->nullable(),
                'pathao_area_name' => fn () => $table->string('pathao_area_name', 120)->nullable(),
                'booked_at' => fn () => $table->timestamp('booked_at')->nullable(),
                'last_synced_at' => fn () => $table->timestamp('last_synced_at')->nullable(),
                'sync_error' => fn () => $table->string('sync_error', 500)->nullable(),
            ];
            foreach ($cols as $name => $add) {
                if (!Schema::hasColumn('order_shipments', $name)) {
                    $add();
                }
            }
        });

        foreach ([
            'order_shipments_courier_account_index' => ['courier_account_id', 'courier_state'],
            'order_shipments_consignment_index' => ['courier_provider', 'consignment_id'],
        ] as $name => $cols) {
            if (!$this->hasIndex('order_shipments', $name)) {
                Schema::table('order_shipments', fn (Blueprint $t) => $t->index($cols, $name));
            }
        }

        if (!Schema::hasTable('courier_webhook_events')) {
            Schema::create('courier_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('courier_account_id')->nullable();
                $table->string('provider', 30);
                $table->string('dedupe_key', 191);
                $table->unsignedBigInteger('shipment_id')->nullable();
                $table->string('result', 50)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['provider', 'dedupe_key'], 'courier_webhook_events_dedupe_unique');
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_webhook_events');
        foreach (['order_shipments_courier_account_index', 'order_shipments_consignment_index'] as $idx) {
            if ($this->hasIndex('order_shipments', $idx)) {
                Schema::table('order_shipments', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }
        Schema::table('order_shipments', function (Blueprint $table) {
            foreach (['courier_account_id', 'courier_provider', 'consignment_id', 'courier_state', 'courier_status', 'courier_status_at',
                'courier_payload', 'tracking_url', 'delivery_fee', 'courier_cod_collected', 'courier_store_id', 'pathao_city_id', 'pathao_city_name',
                'pathao_zone_id', 'pathao_zone_name', 'pathao_area_id', 'pathao_area_name', 'booked_at', 'last_synced_at', 'sync_error'] as $c) {
                if (Schema::hasColumn('order_shipments', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        if ($this->hasIndex('courier_accounts', 'courier_accounts_webhook_token_index')) {
            Schema::table('courier_accounts', fn (Blueprint $t) => $t->dropIndex('courier_accounts_webhook_token_index'));
        }
        Schema::table('courier_accounts', function (Blueprint $table) {
            foreach (['credentials', 'environment', 'is_default', 'settings', 'webhook_token', 'token_cache', 'token_expires_at', 'last_checked_at', 'last_error'] as $c) {
                if (Schema::hasColumn('courier_accounts', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }
        return false;
    }
};
