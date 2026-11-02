<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the "Collections" module additively:
 *  - permissions row "Collections"
 *  - every staff role gets the same Collections rights it already has on "Categories"
 *  - every subscription plan whose modules include "Categories" also gets "Collections"
 * Nothing is removed or overwritten; re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permId = DB::table('permissions')->where('name', 'Collections')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId(['name' => 'Collections', 'created_at' => $now, 'updated_at' => $now]);
        }

        $catId = DB::table('permissions')->where('name', 'Categories')->value('id');
        if ($catId && Schema::hasTable('role_permissions')) {
            $hasCreateEdit = Schema::hasColumn('role_permissions', 'create') && Schema::hasColumn('role_permissions', 'edit');
            $rows = DB::table('role_permissions')->where('permission_id', $catId)->get();
            foreach ($rows as $row) {
                $exists = DB::table('role_permissions')->where('role_id', $row->role_id)->where('permission_id', $permId)->exists();
                if ($exists) {
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
                if (! is_array($modules) || ! in_array('Categories', $modules, true) || in_array('Collections', $modules, true)) {
                    continue;
                }
                $pos = array_search('Categories', $modules, true);
                array_splice($modules, $pos + 1, 0, ['Collections']);
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode(array_values($modules))]);
            }
        }
    }

    public function down(): void
    {
        // Additive data migration; leave plans/permissions in place on rollback.
    }
};
