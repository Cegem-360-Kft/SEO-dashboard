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
        Schema::table('projects', function (Blueprint $table) {
            // Drop tenant_id column if it exists
            if (Schema::hasColumn('projects', 'tenant_id')) {
                // Try to drop indexes that might include tenant_id
                try {
                    $table->dropIndex(['tenant_id', 'is_active']);
                } catch (Exception $e) {
                    // Index might not exist or have different name
                }

                try {
                    $table->dropIndex(['tenant_id', 'domain']);
                } catch (Exception $e) {
                    // Index might not exist or have different name
                }

                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('tenant_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }
};
