<?php

declare(strict_types=1);

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
        Schema::table('users', function (Blueprint $table) {
            // Remove tenant-specific unique constraint
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropIndex(['tenant_id', 'is_active']);
            $table->dropIndex(['tenant_id', 'role']);

            // Re-add global email unique constraint
            $table->unique('email');

            // Drop tenant_id column
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add tenant_id back
            $table->foreignId('tenant_id')->after('id')->constrained()->cascadeOnDelete();

            // Remove global email unique constraint
            $table->dropUnique(['email']);

            // Re-add tenant-specific constraints
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'role']);
        });
    }
};
