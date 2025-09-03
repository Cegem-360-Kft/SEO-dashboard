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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->after('id')->constrained()->cascadeOnDelete();
            $table->string('phone')->nullable()->after('password');
            $table->json('preferences')->nullable()->after('phone');
            $table->enum('role', ['owner', 'admin', 'manager', 'viewer'])->default('viewer')->after('preferences');
            $table->json('permissions')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('permissions');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('timezone')->default('UTC')->after('last_login_at');
            $table->string('language', 5)->default('en')->after('timezone');
            $table->softDeletes();

            // Update unique constraint to be tenant-scoped
            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn([
                'tenant_id', 'phone', 'preferences', 'role', 'permissions',
                'is_active', 'last_login_at', 'timezone', 'language', 'deleted_at',
            ]);
            $table->dropUnique(['tenant_id', 'email']);
            $table->unique('email');
        });
    }
};
