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
        Schema::create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('keyword_hash')->index(); // MD5 hash for deduplication
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->json('categories')->nullable(); // ['brand', 'product', 'service'] for organization
            $table->enum('intent', ['informational', 'navigational', 'commercial', 'transactional'])->nullable();
            $table->string('country', 2)->default('US'); // ISO country code
            $table->string('language', 5)->default('en'); // Language code
            $table->string('location')->nullable(); // City/region for local SEO
            $table->integer('search_volume')->nullable(); // Monthly search volume
            $table->decimal('difficulty_score', 5, 2)->nullable(); // 0-100 difficulty rating
            $table->decimal('cpc', 8, 2)->nullable(); // Cost per click in USD
            $table->decimal('competition', 3, 2)->nullable(); // 0-1 competition level
            $table->json('related_keywords')->nullable(); // LSI and related keyword suggestions
            $table->integer('current_position')->nullable(); // Latest position cache
            $table->integer('previous_position')->nullable(); // Previous position for change tracking
            $table->date('position_last_updated')->nullable();
            $table->boolean('is_tracking_active')->default(true);
            $table->json('tags')->nullable(); // User-defined tags for organization
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'project_id', 'keyword_hash', 'country', 'language']);
            $table->index(['tenant_id', 'project_id', 'is_tracking_active']);
            $table->index(['project_id', 'priority']);
            $table->index(['project_id', 'current_position']);
            $table->index('position_last_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
