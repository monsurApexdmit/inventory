<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_follow_ups')) {
            Schema::create('order_follow_ups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('sell_id');
                // Actor id (saas user / staff / legacy user), no FK because it spans tables
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('staff_name', 150)->nullable();
                // confirmed | no_answer | call_later | wrong_number | customer_cancelled | other
                $table->string('outcome', 32);
                // call | whatsapp | sms | other
                $table->string('channel', 16)->default('call');
                $table->text('note')->nullable();
                $table->dateTime('next_follow_up_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['sell_id', 'created_at']);
            });
        }

        Schema::table('sells', function (Blueprint $table) {
            if (! Schema::hasColumn('sells', 'follow_up_count')) {
                $table->unsignedInteger('follow_up_count')->default(0);
            }
            if (! Schema::hasColumn('sells', 'last_follow_up_at')) {
                $table->dateTime('last_follow_up_at')->nullable();
            }
            if (! Schema::hasColumn('sells', 'last_follow_up_outcome')) {
                $table->string('last_follow_up_outcome', 32)->nullable();
            }
            if (! Schema::hasColumn('sells', 'next_follow_up_at')) {
                $table->dateTime('next_follow_up_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_follow_ups');
        foreach (['next_follow_up_at', 'last_follow_up_outcome', 'last_follow_up_at', 'follow_up_count'] as $col) {
            if (Schema::hasColumn('sells', $col)) {
                Schema::table('sells', function (Blueprint $table) use ($col) {
                    if ($col === 'next_follow_up_at') {
                        $table->dropIndex(['next_follow_up_at']);
                    }
                    $table->dropColumn($col);
                });
            }
        }
    }
};
