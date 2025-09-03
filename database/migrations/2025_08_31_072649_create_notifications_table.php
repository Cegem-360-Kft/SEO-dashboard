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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'position_drop', 
                'position_gain', 
                'new_top_10', 
                'lost_top_10',
                'featured_snippet_gained',
                'featured_snippet_lost',
                'competitor_activity',
                'technical_issue',
                'report_ready',
                'crawl_error',
                'system_alert'
            ]);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable(); // Additional context data
            $table->enum('channel', ['email', 'sms', 'push', 'in_app'])->default('in_app');
            $table->boolean('is_read')->default(false);
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('delivery_status')->nullable(); // Track delivery across channels
            $table->timestamps();
            
            $table->index(['tenant_id', 'user_id', 'is_read']);
            $table->index(['tenant_id', 'type', 'severity']);
            $table->index(['project_id', 'type', 'created_at']);
            $table->index(['is_sent', 'created_at']); // For notification queue processing
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
