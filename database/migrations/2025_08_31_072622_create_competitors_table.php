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
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Business/brand name
            $table->string('domain')->index(); // competitor.com
            $table->string('url'); // Full URL
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->json('categories')->nullable(); // ['direct', 'indirect', 'industry']
            $table->integer('estimated_traffic')->nullable(); // Monthly organic traffic estimate
            $table->integer('domain_authority')->nullable(); // DA/DR score
            $table->integer('backlinks_count')->nullable();
            $table->decimal('estimated_value', 12, 2)->nullable(); // Traffic value estimation
            $table->json('top_keywords')->nullable(); // Their most valuable keywords
            $table->json('shared_keywords_count')->nullable(); // Keywords we both rank for
            $table->decimal('visibility_score', 8, 4)->nullable(); // Overall search visibility
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_analyzed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['tenant_id', 'project_id', 'domain']);
            $table->index(['tenant_id', 'project_id', 'is_active']);
            $table->index(['project_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
