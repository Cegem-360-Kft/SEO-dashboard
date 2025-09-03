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
        Schema::create('serp_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index();
            $table->enum('search_engine', ['google', 'bing', 'yahoo', 'duckduckgo'])->default('google');
            $table->enum('device', ['desktop', 'mobile', 'tablet'])->default('desktop');
            $table->enum('feature_type', [
                'featured_snippet',
                'local_pack',
                'knowledge_panel',
                'people_also_ask',
                'image_pack',
                'video_results',
                'news_results',
                'shopping_results',
                'reviews',
                'sitelinks',
                'site_search_box',
                'top_stories',
            ]);
            $table->integer('position')->nullable(); // Position of the feature on SERP
            $table->string('domain')->nullable(); // Domain owning the feature
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->json('data')->nullable(); // Additional feature-specific data
            $table->boolean('is_our_domain')->default(false); // Whether our tracked domain owns this feature
            $table->timestamps();

            $table->index(['tenant_id', 'date', 'feature_type']);
            $table->index(['keyword_id', 'date', 'feature_type']);
            $table->index(['tenant_id', 'feature_type', 'is_our_domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serp_features');
    }
};
