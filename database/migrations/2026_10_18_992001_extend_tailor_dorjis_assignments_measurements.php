<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Dorjis: on-leave status, salary pay type, workload cap ──
        DB::statement("ALTER TABLE tailor_dorjis MODIFY status ENUM('active','on_leave','inactive') NOT NULL DEFAULT 'active'");
        DB::statement("ALTER TABLE tailor_dorjis MODIFY commission_type ENUM('fixed','percentage','salary') NOT NULL DEFAULT 'fixed'");
        Schema::table('tailor_dorjis', function (Blueprint $t) {
            if (!Schema::hasColumn('tailor_dorjis', 'max_pieces')) $t->unsignedInteger('max_pieces')->nullable()->after('commission_value');
        });

        // ── Assignments: task / pieces / rate, paid state ──
        DB::statement("ALTER TABLE tailor_assignments MODIFY work_status ENUM('assigned','in_progress','completed','returned','paid') NOT NULL DEFAULT 'assigned'");
        Schema::table('tailor_assignments', function (Blueprint $t) {
            if (!Schema::hasColumn('tailor_assignments', 'task'))           $t->string('task')->nullable();
            if (!Schema::hasColumn('tailor_assignments', 'pieces'))         $t->unsignedInteger('pieces')->default(1);
            if (!Schema::hasColumn('tailor_assignments', 'rate_per_piece')) $t->decimal('rate_per_piece', 10, 2)->default(0);
            if (!Schema::hasColumn('tailor_assignments', 'paid_amount'))    $t->decimal('paid_amount', 10, 2)->default(0);
            if (!Schema::hasColumn('tailor_assignments', 'completed_at'))   $t->timestamp('completed_at')->nullable();
        });

        // ── Measurements: label, unit, fit, flexible values, version history ──
        Schema::table('tailor_measurements', function (Blueprint $t) {
            if (!Schema::hasColumn('tailor_measurements', 'label'))          $t->string('label')->nullable();
            if (!Schema::hasColumn('tailor_measurements', 'unit'))           $t->string('unit', 10)->default('in');
            if (!Schema::hasColumn('tailor_measurements', 'fit_preference')) $t->string('fit_preference', 20)->default('Regular');
            if (!Schema::hasColumn('tailor_measurements', 'values'))         $t->json('values')->nullable();
            if (!Schema::hasColumn('tailor_measurements', 'history'))        $t->json('history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tailor_measurements', function (Blueprint $t) {
            $t->dropColumn(['label', 'unit', 'fit_preference', 'values', 'history']);
        });
        Schema::table('tailor_assignments', function (Blueprint $t) {
            $t->dropColumn(['task', 'pieces', 'rate_per_piece', 'paid_amount', 'completed_at']);
        });
        Schema::table('tailor_dorjis', function (Blueprint $t) {
            $t->dropColumn('max_pieces');
        });
    }
};
