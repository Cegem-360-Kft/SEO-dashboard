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
        Schema::create('keyword_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->date('date')->index(); // Date of position check
            $table->enum('search_engine', ['google', 'bing', 'yahoo', 'duckduckgo'])->default('google');
            $table->enum('device', ['desktop', 'mobile', 'tablet'])->default('desktop');
            $table->integer('position')->nullable(); // 1-100+ or NULL if not found
            $table->string('url')->nullable(); // Landing page URL for this position
            $table->json('serp_features')->nullable(); // Featured snippets, local pack, etc.
            $table->integer('estimated_traffic')->nullable(); // Calculated based on CTR curves
            $table->decimal('estimated_value', 10, 2)->nullable(); // Traffic value in currency
            $table->boolean('is_featured_snippet')->default(false);
            $table->boolean('is_local_pack')->default(false);
            $table->boolean('is_paid_above')->default(false); // Ads above organic results
            $table->integer('ads_count')->default(0); // Number of ads on SERP
            $table->text('serp_title')->nullable(); // Title as it appears in SERP
            $table->text('serp_description')->nullable(); // Description as it appears in SERP
            $table->timestamp('checked_at'); // Exact time of position check
            $table->timestamps();
            
            // Optimize for large datasets - this table will have millions of records
            $table->unique(['tenant_id', 'keyword_id', 'date', 'search_engine', 'device']);
            $table->index(['tenant_id', 'date']); // For tenant-wide daily reports
            $table->index(['keyword_id', 'date']); // For keyword history queries
            $table->index(['date', 'position']); // For position distribution analysis
            $table->index(['tenant_id', 'date', 'position']); // For dashboard queries
        });
        
        // Partition by date for better performance (PostgreSQL specific)
        // This can be implemented later as the dataset grows
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keyword_positions');
    }
};
