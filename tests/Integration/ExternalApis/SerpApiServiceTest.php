<?php

use App\Services\SerpApiService;
use App\Models\Project;
use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('SERP API Service Integration', function () {
    let('service', fn() => new SerpApiService());
    let('tenant', fn() => Tenant::factory()->create());
    let('project', fn() => Project::factory()->for($this->tenant)->create());

    beforeEach(function () {
        mockExternalApis();
    });

    describe('Position Tracking Integration', function () {
        it('fetches and stores keyword positions successfully', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'keyword' => 'seo tools',
                'country' => 'US'
            ]);

            // Mock successful SERP API response
            Http::fake([
                'serpapi.com/*' => Http::response([
                    'search_metadata' => [
                        'status' => 'Success',
                        'total_time_taken' => 1.23
                    ],
                    'organic_results' => [
                        [
                            'position' => 5,
                            'title' => 'Best SEO Tools 2024',
                            'link' => 'https://example.com/seo-tools',
                            'snippet' => 'Discover the best SEO tools...',
                            'domain' => 'example.com'
                        ],
                        [
                            'position' => 6,
                            'title' => 'Complete SEO Toolkit',
                            'link' => 'https://other.com/tools',
                            'snippet' => 'Everything you need for SEO...',
                            'domain' => 'other.com'
                        ]
                    ]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result)->toBeArray();
            expect($result['status'])->toBe('success');
            expect($result['position'])->toBe(5);

            // Verify position was stored
            $this->assertDatabaseHas('keyword_positions', [
                'keyword_id' => $keyword->id,
                'position' => 5,
                'url' => 'https://example.com/seo-tools'
            ]);
        });

        it('handles API rate limiting gracefully', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Mock rate limit response
            Http::fake([
                'serpapi.com/*' => Http::response([
                    'error' => 'Rate limit exceeded. Please try again later.'
                ], 429)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['status'])->toBe('rate_limited');
            expect($result['retry_after'])->toBeGreaterThan(0);

            // Should not create position record on rate limit
            $this->assertDatabaseMissing('keyword_positions', [
                'keyword_id' => $keyword->id
            ]);
        });

        it('handles API errors and maintains data integrity', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Mock API error
            Http::fake([
                'serpapi.com/*' => Http::response([
                    'error' => 'Invalid API key'
                ], 401)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['status'])->toBe('error');
            expect($result['error'])->toContain('Invalid API key');
        });

        it('tracks multiple keywords efficiently', function () {
            $keywords = Keyword::factory()->count(5)->for($this->project)->for($this->tenant)->create();

            // Mock successful responses for all keywords
            Http::fake([
                'serpapi.com/*' => Http::response([
                    'search_metadata' => ['status' => 'Success'],
                    'organic_results' => [
                        [
                            'position' => fake()->numberBetween(1, 100),
                            'title' => fake()->sentence(),
                            'link' => fake()->url(),
                            'snippet' => fake()->paragraph(),
                            'domain' => fake()->domainName()
                        ]
                    ]
                ], 200)
            ]);

            $results = $this->service->trackMultipleKeywords($keywords);

            expect($results)->toHaveCount(5);
            
            foreach ($results as $result) {
                expect($result['status'])->toBe('success');
            }

            // Verify all positions were stored
            expect(KeywordPosition::whereIn('keyword_id', $keywords->pluck('id')))->toHaveCount(5);
        });

        it('handles partial failures in batch operations', function () {
            $keywords = Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->create();

            // Mock mixed responses
            Http::fake([
                'serpapi.com/*' => Http::sequence()
                    ->push(['organic_results' => [['position' => 10]]], 200) // Success
                    ->push(['error' => 'Invalid query'], 400)                  // Error
                    ->push(['organic_results' => [['position' => 15]]], 200)  // Success
            ]);

            $results = $this->service->trackMultipleKeywords($keywords);

            $successCount = collect($results)->where('status', 'success')->count();
            $errorCount = collect($results)->where('status', 'error')->count();

            expect($successCount)->toBe(2);
            expect($errorCount)->toBe(1);

            // Verify only successful positions were stored
            expect(KeywordPosition::whereIn('keyword_id', $keywords->pluck('id')))->toHaveCount(2);
        });
    });

    describe('SERP Features Detection', function () {
        it('detects and stores featured snippets', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'answer_box' => [
                        'type' => 'featured_snippet',
                        'title' => 'What is SEO?',
                        'snippet' => 'SEO stands for Search Engine Optimization...',
                        'link' => 'https://example.com/what-is-seo'
                    ],
                    'organic_results' => [
                        [
                            'position' => 1,
                            'title' => 'Complete SEO Guide',
                            'link' => 'https://example.com/seo-guide'
                        ]
                    ]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['serp_features']['featured_snippet'])->toBeTrue();

            // Verify SERP feature was stored
            $this->assertDatabaseHas('serp_features', [
                'keyword_id' => $keyword->id,
                'feature_type' => 'featured_snippet'
            ]);
        });

        it('detects local pack results', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'keyword' => 'restaurants near me'
            ]);

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'local_results' => [
                        [
                            'position' => 1,
                            'title' => 'Best Restaurant',
                            'address' => '123 Main St',
                            'rating' => 4.5,
                            'reviews' => 250
                        ]
                    ],
                    'organic_results' => []
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['serp_features']['local_pack'])->toBeTrue();

            $this->assertDatabaseHas('serp_features', [
                'keyword_id' => $keyword->id,
                'feature_type' => 'local_pack'
            ]);
        });

        it('detects image packs and video results', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'images_results' => [
                        ['title' => 'SEO Image 1'],
                        ['title' => 'SEO Image 2']
                    ],
                    'video_results' => [
                        [
                            'title' => 'SEO Tutorial Video',
                            'link' => 'https://youtube.com/watch?v=123',
                            'duration' => '15:30'
                        ]
                    ],
                    'organic_results' => [
                        ['position' => 1, 'title' => 'SEO Guide']
                    ]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['serp_features']['image_pack'])->toBeTrue();
            expect($result['serp_features']['video'])->toBeTrue();
        });
    });

    describe('API Configuration and Error Handling', function () {
        it('validates API key configuration', function () {
            config(['services.serp_api.key' => null]);

            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            expect(function () use ($keyword) {
                $this->service->trackKeywordPosition($keyword);
            })->toThrow(\Exception::class, 'SERP API key not configured');
        });

        it('handles network timeouts gracefully', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Mock timeout
            Http::fake([
                'serpapi.com/*' => function () {
                    throw new \Illuminate\Http\Client\ConnectionException('Connection timeout');
                }
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['status'])->toBe('timeout');
            expect($result['retry'])->toBeTrue();
        });

        it('handles malformed API responses', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response('Invalid JSON response', 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['status'])->toBe('error');
            expect($result['error'])->toContain('Invalid response format');
        });

        it('respects API usage limits', function () {
            $keywords = Keyword::factory()->count(1000)->for($this->project)->for($this->tenant)->create();

            // Mock responses
            Http::fake([
                'serpapi.com/*' => Http::response(['organic_results' => []], 200)
            ]);

            $results = $this->service->trackMultipleKeywords($keywords, [
                'batch_size' => 100,
                'delay' => 1 // 1 second delay between batches
            ]);

            // Should process in batches with delays
            expect($results)->toHaveCount(1000);
            
            // Verify API was called with proper rate limiting
            Http::assertSentCount(1000);
        });
    });

    describe('Data Accuracy and Validation', function () {
        it('validates position data accuracy', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'keyword' => 'example seo tool'
            ]);

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        [
                            'position' => 3,
                            'title' => 'Example SEO Tool - Best Solution',
                            'link' => 'https://example.com/seo-tool',
                            'snippet' => 'Our SEO tool helps you...',
                            'domain' => 'example.com'
                        ]
                    ]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            // Verify data accuracy
            expect($result['position'])->toBe(3);
            expect($result['url'])->toBe('https://example.com/seo-tool');
            expect($result['title'])->toContain('Example SEO Tool');

            // Verify stored data matches API response
            $position = KeywordPosition::where('keyword_id', $keyword->id)->first();
            expect($position->position)->toBe(3);
            expect($position->url)->toBe('https://example.com/seo-tool');
        });

        it('handles position changes correctly', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'current_position' => 10
            ]);

            // Create historical position
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 10,
                'date' => now()->subDay()->format('Y-m-d')
            ]);

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        [
                            'position' => 5,
                            'title' => 'Improved Ranking',
                            'link' => 'https://example.com/improved'
                        ]
                    ]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['position_change'])->toBe(5); // Improved by 5 positions
            expect($result['trend'])->toBe('improving');

            // Verify keyword model is updated
            $keyword->refresh();
            expect($keyword->current_position)->toBe(5);
            expect($keyword->previous_position)->toBe(10);
        });

        it('handles non-ranking keywords correctly', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        // No results containing the target domain
                        [
                            'position' => 1,
                            'domain' => 'competitor.com'
                        ]
                    ]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            expect($result['position'])->toBeNull();
            expect($result['ranking'])->toBeFalse();

            // Should still create position record with null position
            $this->assertDatabaseHas('keyword_positions', [
                'keyword_id' => $keyword->id,
                'position' => null
            ]);
        });
    });

    describe('Performance and Scalability', function () {
        it('processes large keyword sets efficiently', function () {
            $keywords = Keyword::factory()->count(50)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [
                        ['position' => fake()->numberBetween(1, 100)]
                    ]
                ], 200)
            ]);

            $startTime = microtime(true);
            $results = $this->service->trackMultipleKeywords($keywords, ['concurrent' => true]);
            $endTime = microtime(true);

            $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds

            expect($results)->toHaveCount(50);
            expect($duration)->toBeLessThan(10000); // Should complete within 10 seconds
        });

        it('handles concurrent API requests safely', function () {
            $keywords = Keyword::factory()->count(10)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'organic_results' => [['position' => 1]]
                ], 200)
            ]);

            // Simulate concurrent processing
            $promises = [];
            foreach ($keywords as $keyword) {
                $promises[] = $this->service->trackKeywordPositionAsync($keyword);
            }

            $results = collect($promises)->map(function ($promise) {
                return $promise->wait();
            });

            expect($results)->toHaveCount(10);
            
            // Verify no data corruption occurred
            expect(KeywordPosition::count())->toBe(10);
        });
    });

    describe('Monitoring and Logging', function () {
        it('logs API usage and performance metrics', function () {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'search_metadata' => [
                        'total_time_taken' => 1.25,
                        'credits_used' => 1
                    ],
                    'organic_results' => [['position' => 5]]
                ], 200)
            ]);

            $result = $this->service->trackKeywordPosition($keyword);

            // Verify metrics are logged
            expect($result['api_response_time'])->toBe(1.25);
            expect($result['credits_used'])->toBe(1);
        });

        it('tracks API quota usage', function () {
            $keywords = Keyword::factory()->count(5)->for($this->project)->for($this->tenant)->create();

            Http::fake([
                'serpapi.com/*' => Http::response([
                    'search_metadata' => ['credits_used' => 1],
                    'organic_results' => []
                ], 200)
            ]);

            $this->service->trackMultipleKeywords($keywords);

            $quotaUsage = $this->service->getCurrentQuotaUsage();
            
            expect($quotaUsage['used_today'])->toBe(5);
            expect($quotaUsage['remaining'])->toBeGreaterThan(0);
        });
    });
});