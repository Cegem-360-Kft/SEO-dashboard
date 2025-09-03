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
        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Report creator
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['overview', 'keywords', 'competitors', 'technical', 'custom'])->default('overview');
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'on_demand'])->default('monthly');
            $table->json('config')->nullable(); // Report configuration (metrics, filters, etc.)
            $table->json('recipients')->nullable(); // Email addresses for automated delivery
            $table->enum('format', ['pdf', 'excel', 'html', 'csv'])->default('pdf');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_automated')->default(false);
            $table->date('period_start')->nullable(); // Report period
            $table->date('period_end')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('next_generation_at')->nullable();
            $table->string('file_path')->nullable(); // Path to generated report file
            $table->integer('file_size')->nullable(); // File size in bytes
            $table->enum('status', ['pending', 'generating', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'project_id', 'is_active']);
            $table->index(['tenant_id', 'is_automated', 'next_generation_at']);
            $table->index(['status', 'next_generation_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
