<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the "Marketing" module (ad order links) additively:
 *  - permissions row "Marketing"
 *  - every staff role gets the same Marketing rights it already has on "Orders"
 *  - every subscription plan gets "Marketing" (after "Orders" when present, else appended)
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
        $permId = DB::table('permissions')->where('name', 'Marketing')->value('id');
        if (!$permId) {
            $permId = DB::table('permissions')->insertGetId(['name' => 'Marketing', 'created_at' => $now, 'updated_at' => $now]);
        }

        $ordersId = DB::table('permissions')->where('name', 'Orders')->value('id');
        if ($ordersId && Schema::hasTable('role_permissions')) {
            $hasCreateEdit = Schema::hasColumn('role_permissions', 'create') && Schema::hasColumn('role_permissions', 'edit');
            foreach (DB::table('role_permissions')->where('permission_id', $ordersId)->get() as $row) {
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
                if (!is_array($modules) || in_array('Marketing', $modules, true)) {
                    continue;
                }
                if (in_array('Orders', $modules, true)) {
                    array_splice($modules, array_search('Orders', $modules, true) + 1, 0, ['Marketing']);
                } else {
                    $modules[] = 'Marketing';
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
