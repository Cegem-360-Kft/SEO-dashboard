<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teams = config('permission.teams');

        if ($teams) {
            // Add tenant_id to permissions table
            Schema::table($tableNames['permissions'], function (Blueprint $table) use ($columnNames) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable()->after('guard_name');
                $table->index($columnNames['team_foreign_key'], 'permissions_team_foreign_key_index');
            });

            // Update unique constraint to include tenant_id
            Schema::table($tableNames['permissions'], function (Blueprint $table) use ($columnNames) {
                $table->dropUnique(['name', 'guard_name']);
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $teams = config('permission.teams');

        if ($teams) {
            Schema::table($tableNames['permissions'], function (Blueprint $table) use ($columnNames) {
                $table->dropIndex('permissions_team_foreign_key_index');
                $table->dropColumn($columnNames['team_foreign_key']);
            });

            // Restore original unique constraint
            Schema::table($tableNames['permissions'], function (Blueprint $table) {
                $table->unique(['name', 'guard_name']);
            });
        }
    }
};