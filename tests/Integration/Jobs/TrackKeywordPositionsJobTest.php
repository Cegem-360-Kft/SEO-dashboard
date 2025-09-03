<?php

use App\Jobs\TrackKeywordPositionsJob;
use App\Models\Project;
use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Tenant;
use App\Services\SerpApiService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

describe('Track Keyword Positions Job Integration', function () {
    let('tenant', fn() => Tenant::factory()->create());
    let('project', fn() => Project::factory()->for($this->tenant)->create());

    beforeEach(function () {
        mockExternalApis();
        Queue::fake();
    });

    describe('Job Dispatch and Execution', function () {
        it('dispatches job for project successfully', function () {
            $keywords = Keyword::factory()->count(5)->for($this->project)->for($this->tenant)->create();

            TrackKeywordPositionsJob::dispatch($this->project);

            Queue::assertPushed(TrackKeywordPositionsJob::class, function ($job) {
                return $job->project->id === $this->project->id;
            });
        });

        it('processes keywords in batches', function () {
            $keywords = Keyword::factory()->count(25)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        [
                            'position' => fake()->numberBetween(1, 100),
                            'title' => fake()->sentence(),
                            'link' => fake()->url()
                        ]
                    ]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Verify all keywords were processed
            expect(KeywordPosition::where('tenant_id', $this->tenant->id)->count())->toBe(25);
        });

        it('updates project last_positions_updated_at timestamp', function () {
            $initialTimestamp = $this->project->last_positions_updated_at;
            
            Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 10]]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            $this->project->refresh();
            expect($this->project->last_positions_updated_at)->not->toBe($initialTimestamp);
            expect($this->project->last_positions_updated_at)->toBeInstanceOf(\Carbon\Carbon::class);
        });

        it('handles only active keywords', function () {
            $activeKeywords = Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true
            ]);
            
            $inactiveKeywords = Keyword::factory()->count(2)->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => false
            ]);

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 5]]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Only active keywords should have positions tracked
            expect(KeywordPosition::count())->toBe(3);
        });
    });

    describe('Error Handling and Retry Logic', function () {
        it('handles API rate limiting with retry', function () {
            $keywords = Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->create();

            // Mock rate limit response first, then success
            Http::fake([
                'serpapi.com/*' => Http::sequence()
                    ->push(['error' => 'Rate limit exceeded'], 429)
                    ->push(['organic_results' => [['position' => 10]]], 200)
                    ->push(['organic_results' => [['position' => 15]]], 200)
                    ->push(['organic_results' => [['position' => 20]]], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            
            // First attempt should fail and be retried
            try {
                $job->handle(app(SerpApiService::class));
            } catch (\Exception $e) {
                expect($e->getMessage())->toContain('Rate limit');
            }

            // Simulate retry after delay
            sleep(1);
            $job->handle(app(SerpApiService::class));

            // Should have processed all keywords on retry
            expect(KeywordPosition::count())->toBeGreaterThan(0);
        });

        it('handles partial API failures gracefully', function () {
            $keywords = Keyword::factory()->count(5)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::sequence()
                    ->push(['organic_results' => [['position' => 10]]], 200) // Success
                    ->push(['error' => 'Invalid query'], 400)                 // Error
                    ->push(['organic_results' => [['position' => 15]]], 200) // Success
                    ->push(['organic_results' => [['position' => 20]]], 200) // Success
                    ->push(['organic_results' => [['position' => 25]]], 200) // Success
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Should have 4 successful position records (1 failed)
            expect(KeywordPosition::count())->toBe(4);
        });

        it('fails job after maximum retries', function () {
            $keywords = Keyword::factory()->count(2)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response(['error' => 'Service unavailable'], 503)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->tries = 3; // Set retry limit

            $attempts = 0;
            while ($attempts < 3) {
                try {
                    $job->handle(app(SerpApiService::class));
                } catch (\Exception $e) {
                    $attempts++;
                    if ($attempts >= 3) {
                        expect($e->getMessage())->toContain('Service unavailable');
                        break;
                    }
                }
            }

            expect($attempts)->toBe(3);
        });

        it('handles network timeouts with exponential backoff', function () {
            $keywords = Keyword::factory()->count(2)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => function () {
                    throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
                }
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            
            $startTime = microtime(true);
            
            try {
                $job->handle(app(SerpApiService::class));
            } catch (\Exception $e) {
                expect($e->getMessage())->toContain('timeout');
            }

            $duration = microtime(true) - $startTime;
            
            // Should have attempted with proper timeout handling
            expect($duration)->toBeGreaterThan(1); // At least 1 second for timeout
        });
    });

    describe('Performance and Resource Management', function () {
        it('processes large keyword sets efficiently', function () {
            $keywords = Keyword::factory()->count(100)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => fake()->numberBetween(1, 100)]]
                ], 200)
            ]);

            $startTime = microtime(true);
            
            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));
            
            $duration = microtime(true) - $startTime;

            expect(KeywordPosition::count())->toBe(100);
            expect($duration)->toBeLessThan(30); // Should complete within 30 seconds
        });

        it('manages memory usage for large datasets', function () {
            $keywords = Keyword::factory()->count(1000)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 1]]
                ], 200)
            ]);

            $initialMemory = memory_get_usage();
            
            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));
            
            $finalMemory = memory_get_usage();
            $memoryIncrease = $finalMemory - $initialMemory;

            // Memory increase should be reasonable (less than 50MB)
            expect($memoryIncrease)->toBeLessThan(50 * 1024 * 1024);
        });

        it('respects API rate limits', function () {
            $keywords = Keyword::factory()->count(10)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 1]]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->rateLimitPerMinute = 60; // 60 requests per minute
            
            $startTime = microtime(true);
            $job->handle(app(SerpApiService::class));
            $duration = microtime(true) - $startTime;

            // With rate limiting, should take at least a few seconds
            expect($duration)->toBeGreaterThan(2);
            expect(KeywordPosition::count())->toBe(10);
        });
    });

    describe('Data Integrity and Consistency', function () {
        it('ensures transactional integrity', function () {
            $keywords = Keyword::factory()->count(5)->for($this->project)->for($this->tenant)->create();

            // Mock mixed responses with one failure that should trigger rollback
            Http::fake([
                'serpapi.com/*' => Http::sequence()
                    ->push(['organic_results' => [['position' => 10]]], 200)
                    ->push(['organic_results' => [['position' => 15]]], 200)
                    ->push(['error' => 'Critical error'], 500)
                    ->push(['organic_results' => [['position' => 25]]], 200)
                    ->push(['organic_results' => [['position' => 30]]], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->failOnError = true; // Configure to fail entire batch on error

            try {
                $job->handle(app(SerpApiService::class));
            } catch (\Exception $e) {
                // Job should fail
                expect($e->getMessage())->toContain('Critical error');
            }

            // No positions should be saved due to transaction rollback
            expect(KeywordPosition::count())->toBe(0);
        });

        it('prevents duplicate position entries', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Create existing position for today
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'date' => now()->format('Y-m-d')
            ]);

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 5]]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Should update existing record, not create duplicate
            expect(KeywordPosition::where('keyword_id', $keyword->id)
                ->where('date', now()->format('Y-m-d'))
                ->count())->toBe(1);
        });

        it('maintains data consistency across tenants', function () {
            $tenant2 = Tenant::factory()->create();
            $project2 = Project::factory()->for($tenant2)->create();
            
            $keywordsTenant1 = Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->create();
            $keywordsTenant2 = Keyword::factory()->count(2)->for($project2)->for($tenant2)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => fake()->numberBetween(1, 50)]]
                ], 200)
            ]);

            // Process both projects
            $job1 = new TrackKeywordPositionsJob($this->project);
            $job2 = new TrackKeywordPositionsJob($project2);

            $job1->handle(app(SerpApiService::class));
            $job2->handle(app(SerpApiService::class));

            // Verify tenant isolation
            $tenant1Positions = KeywordPosition::where('tenant_id', $this->tenant->id)->count();
            $tenant2Positions = KeywordPosition::where('tenant_id', $tenant2->id)->count();

            expect($tenant1Positions)->toBe(3);
            expect($tenant2Positions)->toBe(2);
        });
    });

    describe('Monitoring and Notifications', function () {
        it('logs job progress and metrics', function () {
            $keywords = Keyword::factory()->count(10)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 1]]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Verify audit log entry was created
            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $this->tenant->id,
                'event' => 'keyword_positions.tracked',
                'auditable_type' => Project::class,
                'auditable_id' => $this->project->id
            ]);
        });

        it('creates notifications for significant changes', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'current_position' => 15
            ]);

            // Mock API response showing significant improvement
            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        [
                            'position' => 3, // Improved from 15 to 3
                            'title' => 'Great Improvement!',
                            'link' => 'https://example.com/improved'
                        ]
                    ]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Should create notification for significant improvement
            $this->assertDatabaseHas('notifications', [
                'tenant_id' => $this->tenant->id,
                'keyword_id' => $keyword->id,
                'type' => 'position_gain',
                'severity' => 'high'
            ]);
        });

        it('sends alerts for ranking losses', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'current_position' => 3 // Currently ranking well
            ]);

            // Mock API response showing significant decline
            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        [
                            'position' => 25, // Dropped from 3 to 25
                            'title' => 'Declined Ranking',
                            'link' => 'https://example.com/declined'
                        ]
                    ]
                ], 200)
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->handle(app(SerpApiService::class));

            // Should create alert notification for significant drop
            $this->assertDatabaseHas('notifications', [
                'tenant_id' => $this->tenant->id,
                'keyword_id' => $keyword->id,
                'type' => 'position_drop',
                'severity' => 'critical'
            ]);
        });
    });

    describe('Job Queuing and Scheduling', function () {
        it('can be scheduled for automatic execution', function () {
            $keywords = Keyword::factory()->count(5)->for($this->project)->for($this->tenant)->create();

            // Test job scheduling
            TrackKeywordPositionsJob::dispatch($this->project)->delay(now()->addMinutes(5));

            Queue::assertPushed(TrackKeywordPositionsJob::class, function ($job) {
                return $job->delay && $job->delay->greaterThan(now()->addMinutes(4));
            });
        });

        it('can be queued with priority', function () {
            $highPriorityProject = Project::factory()->for($this->tenant)->create(['priority' => 'high']);
            $lowPriorityProject = Project::factory()->for($this->tenant)->create(['priority' => 'low']);

            TrackKeywordPositionsJob::dispatch($highPriorityProject)->onQueue('high-priority');
            TrackKeywordPositionsJob::dispatch($lowPriorityProject)->onQueue('low-priority');

            Queue::assertPushedOn('high-priority', TrackKeywordPositionsJob::class);
            Queue::assertPushedOn('low-priority', TrackKeywordPositionsJob::class);
        });

        it('handles job uniqueness to prevent duplicates', function () {
            $keywords = Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->create();

            // Dispatch multiple jobs for same project
            TrackKeywordPositionsJob::dispatch($this->project);
            TrackKeywordPositionsJob::dispatch($this->project);
            TrackKeywordPositionsJob::dispatch($this->project);

            // Should only dispatch one unique job per project
            Queue::assertPushed(TrackKeywordPositionsJob::class, 1);
        });
    });

    describe('Cleanup and Maintenance', function () {
        it('cleans up old position data', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Create old position data (90 days old)
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'date' => now()->subDays(90)->format('Y-m-d')
            ]);

            // Create recent position data
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'date' => now()->format('Y-m-d')
            ]);

            $job = new TrackKeywordPositionsJob($this->project);
            $job->cleanupOldData = true;
            $job->dataRetentionDays = 60;

            Http::fake([
                'serpapi.com/*' => Http::response(['organic_results' => []], 200)
            ]);

            $job->handle(app(SerpApiService::class));

            // Old data should be cleaned up
            expect(KeywordPosition::where('date', '<', now()->subDays(60))->count())->toBe(0);
            expect(KeywordPosition::where('date', '>=', now()->subDays(60))->count())->toBeGreaterThan(0);
        });
    });
});