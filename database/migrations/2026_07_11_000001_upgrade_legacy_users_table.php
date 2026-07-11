<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username', 100)->nullable()->after('id');
            }
            if (! Schema::hasColumn('users', 'role_id')) {
                $table->foreignId('role_id')->nullable()->after('password')
                    ->constrained('roles')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('role_id');
            }
            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (Schema::hasColumn('users', 'name')) {
            DB::table('users')->whereNull('username')->update(['username' => DB::raw('name')]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropConstrainedForeignId('role_id');
            }
            $table->dropColumn(array_values(array_filter(
                ['username', 'address', 'deleted_at'],
                fn (string $column) => Schema::hasColumn('users', $column)
            )));
        });
    }
};
