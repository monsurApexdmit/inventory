<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_tickets')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                if (!Schema::hasColumn('support_tickets', 'admin_unread')) {
                    $table->boolean('admin_unread')->default(false);
                }
                if (!Schema::hasColumn('support_tickets', 'last_read_at')) {
                    $table->timestamp('last_read_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('notification_dismissals')) {
            Schema::create('notification_dismissals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                $table->string('alert_key', 100);
                $table->timestamp('dismissed_at')->useCurrent();
                $table->unique(['company_id', 'user_id', 'alert_key'], 'notif_dismiss_unique');
                $table->index(['company_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dismissals');
        if (Schema::hasTable('support_tickets')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                foreach (['admin_unread', 'last_read_at'] as $c) {
                    if (Schema::hasColumn('support_tickets', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
