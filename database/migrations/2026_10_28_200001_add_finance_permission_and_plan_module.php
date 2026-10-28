<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the "Finance" module additively:
 *  - permissions row "Finance"
 *  - every staff role gets the same Finance rights it already has on "Expenses"
 *  - every subscription plan gets "Finance" (after "Expenses" when present, else after "Dashboard", else appended)
 * Nothing is removed or overwritten; re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permId = DB::table('permissions')->where('name', 'Finance')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId(['name' => 'Finance', 'created_at' => $now, 'updated_at' => $now]);
        }

        $expId = DB::table('permissions')->where('name', 'Expenses')->value('id');
        if ($expId && Schema::hasTable('role_permissions')) {
            $hasCreateEdit = Schema::hasColumn('role_permissions', 'create') && Schema::hasColumn('role_permissions', 'edit');
            foreach (DB::table('role_permissions')->where('permission_id', $expId)->get() as $row) {
                if (DB::table('role_permissions')->where('role_id', $row->role_id)->where('permission_id', $permId)->exists()) {
                    continue;
                }
                $insert = [
                    'role_id' => $row->role_id,
                    'permission_id' => $permId,
                    'read' => (bool) $row->read,
                    'write' => (bool) $row->write,
                    'delete' => (bool) $row->delete,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hasCreateEdit) {
                    $insert['create'] = (bool) $row->create;
                    $insert['edit'] = (bool) $row->edit;
                }
                DB::table('role_permissions')->insert($insert);
            }
        }

        if (Schema::hasTable('subscription_plans') && Schema::hasColumn('subscription_plans', 'modules')) {
            foreach (DB::table('subscription_plans')->get(['id', 'modules']) as $plan) {
                $modules = is_string($plan->modules) ? json_decode($plan->modules, true) : $plan->modules;
                if (!is_array($modules) || in_array('Finance', $modules, true)) {
                    continue;
                }
                $anchor = in_array('Expenses', $modules, true) ? 'Expenses' : (in_array('Dashboard', $modules, true) ? 'Dashboard' : null);
                if ($anchor !== null) {
                    array_splice($modules, array_search($anchor, $modules, true) + 1, 0, ['Finance']);
                } else {
                    $modules[] = 'Finance';
                }
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode(array_values($modules))]);
            }
        }
    }

    public function down(): void
    {
        // Additive data migration; leave plans/permissions in place on rollback.
    }
};
