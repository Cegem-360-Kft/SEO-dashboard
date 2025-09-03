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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Project owner
            $table->string('name');
            $table->string('url');
            $table->string('domain')->index(); // Extracted from URL for quick filtering
            $table->text('description')->nullable();
            $table->json('target_countries')->nullable(); // ['US', 'UK', 'CA'] for geo-targeting
            $table->json('target_languages')->nullable(); // ['en', 'es', 'fr'] for multi-language support
            $table->json('search_engines')->default('["google"]'); // ['google', 'bing', 'yahoo']
            $table->json('devices')->default('["desktop", "mobile"]'); // Device tracking preferences
            $table->json('integrations')->nullable(); // GSC, GA4, etc. connection details
            $table->json('settings')->nullable(); // Crawl frequency, alerts configuration
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('last_positions_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'domain']);
            $table->index('last_positions_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
