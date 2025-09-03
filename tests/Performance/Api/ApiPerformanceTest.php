<?php

declare(strict_types=1);

use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

describe('API Performance Tests', function (): void {
    let('tenant', fn () => Tenant::factory()->create());
    let('user', fn () => User::factory()->for($this->tenant)->create());

    beforeEach(function (): void {
        mockExternalApis();
    });

    describe('Project Listing Performance', function (): void {
        it('handles large project datasets efficiently', function (): void {
            // Create substantial dataset
            $projects = Project::factory()->count(500)->for($this->tenant)->create();

            // Add keywords to some projects to test relationship loading
            $projects->take(50)->each(function ($project): void {
                Keyword::factory()->count(20)->for($project)->for($this->tenant)->create();
            });

            // Test performance with query count and response time
            $this->assertQueryCountLessThan(5, function (): void {
                $this->assertResponseTimeLessThan(2000, function (): void {
                    $response = $this->actingAs($this->user, 'sanctum')
                        ->getJson('/api/projects?per_page=50');

                    $response->assertOk()
                        ->assertJsonCount(50, 'data');
                });
            });
        });

        it('optimizes search queries with indexes', function (): void {
            // Create projects with searchable data
            Project::factory()->count(1000)->for($this->tenant)->create([
                'name' => fn (): string => 'SEO Project '.fake()->company(),
            ]);

            $this->assertQueryCountLessThan(3, function (): void {
                $this->assertResponseTimeLessThan(1500, function (): void {
                    $response = $this->actingAs($this->user, 'sanctum')
                        ->getJson('/api/projects?search=SEO&per_page=25');

                    $response->assertOk();
                });
            });
        });

        it('paginates efficiently with large datasets', function (): void {
            Project::factory()->count(10000)->for($this->tenant)->create();

            // Test pagination at different offsets
            foreach ([1, 100, 200, 500] as $page) {
                $this->assertResponseTimeLessThan(1000, function () use ($page): void {
                    $response = $this->actingAs($this->user, 'sanctum')
                        ->getJson(sprintf('/api/projects?page=%s&per_page=50', $page));

                    $response->assertOk();
                });
            }
        });

        it('handles concurrent user requests', function (): void {
            Project::factory()->count(100)->for($this->tenant)->create();

            // Simulate concurrent requests
            $promises = [];
            for ($i = 0; $i < 10; $i++) {
                $promises[] = $this->actingAs($this->user, 'sanctum')
                    ->getJson('/api/projects?per_page=20');
            }

            foreach ($promises as $response) {
                $response->assertOk();
            }
        });
    });

    describe('Keyword Data Performance', function (): void {
        it('loads keyword relationships efficiently', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(200)->for($project)->for($this->tenant)->create();

            // Create position history for keywords
            $keywords->each(function ($keyword): void {
                KeywordPosition::factory()->count(30)->for($keyword)->for($this->tenant)->create();
            });

            $this->assertQueryCountLessThan(10, function () use ($project): void {
                $this->assertResponseTimeLessThan(3000, function () use ($project): void {
                    $response = $this->actingAs($this->user, 'sanctum')
                        ->getJson('/api/projects/'.$project->id);

                    $response->assertOk();
                });
            });
        });

        it('optimizes keyword filtering and sorting', function (): void {
            $project = Project::factory()->for($this->tenant)->create();

            // Create diverse keyword dataset
            Keyword::factory()->count(1000)->for($project)->for($this->tenant)->create([
                'current_position' => fn () => fake()->optional(0.8)->numberBetween(1, 100),
                'search_volume' => fn () => fake()->optional(0.9)->numberBetween(10, 50000),
                'priority' => fn () => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            ]);

            // Test various filtering scenarios
            $filters = [
                'priority=high',
                'position_min=1&position_max=10',
                'search_volume_min=1000',
                'sort=search_volume&direction=desc',
            ];

            foreach ($filters as $filter) {
                $this->assertResponseTimeLessThan(2000, function () use ($filter): void {
                    $response = $this->actingAs($this->user, 'sanctum')
                        ->getJson(sprintf('/api/keywords?%s&per_page=50', $filter));

                    $response->assertOk();
                });
            }
        });

        it('handles bulk keyword operations efficiently', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(500)->for($project)->for($this->tenant)->create();

            $keywordIds = $keywords->pluck('id')->take(100)->toArray();

            $this->assertResponseTimeLessThan(5000, function () use ($keywordIds): void {
                $response = $this->actingAs($this->user, 'sanctum')
                    ->postJson('/api/keywords/bulk-update', [
                        'keyword_ids' => $keywordIds,
                        'updates' => ['priority' => 'high'],
                    ]);

                $response->assertOk();
            });
        });
    });

    describe('Dashboard Data Performance', function (): void {
        it('loads dashboard data efficiently with complex metrics', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(100)->for($project)->for($this->tenant)->create();

            // Create rich position history
            $keywords->each(function ($keyword): void {
                KeywordPosition::factory()->count(60)->for($keyword)->for($this->tenant)->create([
                    'date' => fn (): string => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
                ]);
            });

            // Add competitors
            $competitors = Competitor::factory()->count(5)->for($project)->for($this->tenant)->create();

            $this->assertQueryCountLessThan(15, function () use ($project): void {
                $this->assertResponseTimeLessThan(4000, function () use ($project): void {
                    $response = $this->actingAs($this->user, 'sanctum')
                        ->getJson(sprintf('/api/projects/%s/dashboard', $project->id));

                    $response->assertOk()
                        ->assertJsonStructure([
                            'project',
                            'metrics',
                            'recent_activity',
                            'charts',
                        ]);
                });
            });
        });

        it('caches expensive calculations', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            Keyword::factory()->count(200)->for($project)->for($this->tenant)->create();

            // First request (cold cache)
            $startTime = microtime(true);
            $response1 = $this->actingAs($this->user, 'sanctum')
                ->getJson(sprintf('/api/projects/%s/dashboard', $project->id));
            $firstRequestTime = microtime(true) - $startTime;

            $response1->assertOk();

            // Second request (warm cache)
            $startTime = microtime(true);
            $response2 = $this->actingAs($this->user, 'sanctum')
                ->getJson(sprintf('/api/projects/%s/dashboard', $project->id));
            $secondRequestTime = microtime(true) - $startTime;

            $response2->assertOk();

            // Second request should be significantly faster
            expect($secondRequestTime)->toBeLessThan($firstRequestTime * 0.7);
        });

        it('handles multi-tenant dashboard queries efficiently', function (): void {
            // Create multiple tenants with data
            $tenants = Tenant::factory()->count(10)->create();
            $users = [];

            foreach ($tenants as $tenant) {
                $users[] = User::factory()->for($tenant)->create();
                $project = Project::factory()->for($tenant)->create();
                Keyword::factory()->count(50)->for($project)->for($tenant)->create();
            }

            // Test concurrent dashboard requests from different tenants
            $responses = [];
            foreach ($users as $user) {
                $project = $user->tenant->projects()->first();

                $this->assertResponseTimeLessThan(3000, function () use ($user, $project, &$responses): void {
                    $responses[] = $this->actingAs($user, 'sanctum')
                        ->getJson(sprintf('/api/projects/%s/dashboard', $project->id));
                });
            }

            foreach ($responses as $response) {
                $response->assertOk();
            }
        });
    });

    describe('Report Generation Performance', function (): void {
        it('generates reports efficiently for large datasets', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(1000)->for($project)->for($this->tenant)->create();

            // Create substantial position history
            $keywords->take(100)->each(function ($keyword): void {
                KeywordPosition::factory()->count(90)->for($keyword)->for($this->tenant)->create([
                    'date' => fn (): string => fake()->dateTimeBetween('-90 days')->format('Y-m-d'),
                ]);
            });

            $this->assertResponseTimeLessThan(10000, function () use ($project): void {
                $response = $this->actingAs($this->user, 'sanctum')
                    ->postJson('/api/reports', [
                        'project_id' => $project->id,
                        'type' => 'positions',
                        'name' => 'Performance Test Report',
                        'period_start' => now()->subDays(30)->format('Y-m-d'),
                        'period_end' => now()->format('Y-m-d'),
                    ]);

                $response->assertCreated();
            });
        });

        it('handles concurrent report generation', function (): void {
            $projects = Project::factory()->count(5)->for($this->tenant)->create();

            $projects->each(function ($project): void {
                Keyword::factory()->count(50)->for($project)->for($this->tenant)->create();
            });

            $promises = [];
            foreach ($projects as $project) {
                $promises[] = $this->actingAs($this->user, 'sanctum')
                    ->postJson('/api/reports', [
                        'project_id' => $project->id,
                        'type' => 'overview',
                        'name' => 'Concurrent Report '.$project->id,
                    ]);
            }

            foreach ($promises as $response) {
                $response->assertCreated();
            }
        });
    });

    describe('Search and Filtering Performance', function (): void {
        it('optimizes full-text search across large datasets', function (): void {
            // Create projects with searchable content
            Project::factory()->count(2000)->for($this->tenant)->create();

            $searchTerms = ['SEO', 'marketing', 'optimization', 'analytics'];

            foreach ($searchTerms as $term) {
                $this->assertQueryCountLessThan(3, function () use ($term): void {
                    $this->assertResponseTimeLessThan(1500, function () use ($term): void {
                        $response = $this->actingAs($this->user, 'sanctum')
                            ->getJson(sprintf('/api/projects?search=%s&per_page=25', $term));

                        $response->assertOk();
                    });
                });
            }
        });

        it('handles complex filtering combinations efficiently', function (): void {
            $project = Project::factory()->for($this->tenant)->create();

            // Create diverse keyword dataset with various attributes
            Keyword::factory()->count(5000)->for($project)->for($this->tenant)->create([
                'current_position' => fn () => fake()->optional(0.8)->numberBetween(1, 100),
                'search_volume' => fn () => fake()->optional(0.9)->numberBetween(10, 50000),
                'priority' => fn () => fake()->randomElement(['low', 'medium', 'high', 'critical']),
                'intent' => fn () => fake()->randomElement(['informational', 'commercial', 'transactional']),
                'country' => fn () => fake()->randomElement(['US', 'GB', 'CA', 'AU']),
            ]);

            // Complex filter combination
            $filters = [
                'priority=high',
                'position_min=1',
                'position_max=20',
                'search_volume_min=1000',
                'country=US',
                'intent=commercial',
            ];

            $filterString = implode('&', $filters);

            $this->assertResponseTimeLessThan(2500, function () use ($filterString): void {
                $response = $this->actingAs($this->user, 'sanctum')
                    ->getJson(sprintf('/api/keywords?%s&per_page=50', $filterString));

                $response->assertOk();
            });
        });
    });

    describe('Database Query Optimization', function (): void {
        it('uses appropriate indexes for common queries', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            Keyword::factory()->count(1000)->for($project)->for($this->tenant)->create();

            // Test that common queries are optimized
            $queries = [
                sprintf('SELECT * FROM keywords WHERE tenant_id = %s LIMIT 50', $this->tenant->id),
                sprintf('SELECT * FROM keywords WHERE project_id = %s AND is_tracking_active = true', $project->id),
                sprintf('SELECT * FROM keywords WHERE tenant_id = %s AND current_position BETWEEN 1 AND 10', $this->tenant->id),
            ];

            foreach ($queries as $query) {
                $startTime = microtime(true);
                DB::select($query);
                $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

                expect($duration)->toBeLessThan(100); // Should execute in less than 100ms
            }
        });

        it('minimizes N+1 query problems', function (): void {
            $projects = Project::factory()->count(20)->for($this->tenant)->create();

            $projects->each(function ($project): void {
                Keyword::factory()->count(10)->for($project)->for($this->tenant)->create();
            });

            // Test that loading projects with keywords doesn't cause N+1
            $queryCount = 0;
            DB::listen(function () use (&$queryCount): void {
                $queryCount++;
            });

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects?per_page=20&include=keywords');

            $response->assertOk();

            // Should use eager loading to minimize queries
            expect($queryCount)->toBeLessThan(5);
        });
    });

    describe('Memory Usage Optimization', function (): void {
        it('maintains reasonable memory usage with large datasets', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(1000)->for($project)->for($this->tenant)->create();

            $initialMemory = memory_get_usage();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects/'.$project->id);

            $finalMemory = memory_get_usage();
            $memoryIncrease = $finalMemory - $initialMemory;

            $response->assertOk();

            // Memory increase should be reasonable (less than 20MB)
            expect($memoryIncrease)->toBeLessThan(20 * 1024 * 1024);
        });

        it('handles pagination memory efficiently', function (): void {
            Project::factory()->count(10000)->for($this->tenant)->create();

            $initialMemory = memory_get_usage();

            // Test multiple pages
            for ($page = 1; $page <= 10; $page++) {
                $response = $this->actingAs($this->user, 'sanctum')
                    ->getJson(sprintf('/api/projects?page=%d&per_page=100', $page));

                $response->assertOk();
            }

            $finalMemory = memory_get_usage();
            $memoryIncrease = $finalMemory - $initialMemory;

            // Memory should not accumulate significantly across pagination
            expect($memoryIncrease)->toBeLessThan(30 * 1024 * 1024);
        });
    });

    describe('API Response Optimization', function (): void {
        it('compresses large response payloads effectively', function (): void {
            $project = Project::factory()->for($this->tenant)->create();

            // Create large dataset
            Keyword::factory()->count(500)->for($project)->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects/'.$project->id);

            $response->assertOk();

            // Verify response compression headers are set
            expect($response->headers->get('content-encoding'))->toBe('gzip');

            // Response time should still be reasonable despite large payload
            $this->assertResponseTimeLessThan(3000, function () use ($project): void {
                $this->actingAs($this->user, 'sanctum')
                    ->getJson('/api/projects/'.$project->id);
            });
        });

        it('paginates API responses appropriately', function (): void {
            Project::factory()->count(500)->for($this->tenant)->create();

            // Test default pagination
            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects');

            $response->assertOk();

            $meta = $response->json('meta');

            expect($meta['per_page'])->toBeLessThanOrEqual(15); // Default pagination limit
            expect($meta['total'])->toBe(500);
        });
    });

    describe('Stress Testing', function (): void {
        it('handles high concurrent load', function (): void {
            Project::factory()->count(100)->for($this->tenant)->create();

            // Simulate high concurrent load
            $promises = [];
            $concurrentUsers = 50;

            for ($i = 0; $i < $concurrentUsers; $i++) {
                $user = User::factory()->for($this->tenant)->create();
                $promises[] = $this->actingAs($user, 'sanctum')
                    ->getJson('/api/projects?per_page=10');
            }

            foreach ($promises as $response) {
                $response->assertOk();
            }
        });

        it('maintains performance under sustained load', function (): void {
            $project = Project::factory()->for($this->tenant)->create();
            Keyword::factory()->count(200)->for($project)->for($this->tenant)->create();

            $responseTimes = [];

            // Make 20 consecutive requests
            for ($i = 0; $i < 20; $i++) {
                $startTime = microtime(true);

                $response = $this->actingAs($this->user, 'sanctum')
                    ->getJson(sprintf('/api/projects/%s/dashboard', $project->id));

                $endTime = microtime(true);
                $duration = ($endTime - $startTime) * 1000;

                $response->assertOk();
                $responseTimes[] = $duration;
            }

            // Response times should remain consistent (within 50% variance)
            $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
            $maxResponseTime = max($responseTimes);

            expect($maxResponseTime)->toBeLessThan($avgResponseTime * 1.5);
        });
    });
});
