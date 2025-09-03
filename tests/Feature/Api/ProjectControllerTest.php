<?php

use App\Models\User;
use App\Models\Tenant;
use App\Models\Project;
use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Competitor;
use Illuminate\Http\Response;

describe('Project API Controller', function () {
    let('tenant', fn() => Tenant::factory()->create());
    let('user', fn() => User::factory()->for($this->tenant)->create());
    let('otherTenant', fn() => Tenant::factory()->create());
    let('otherUser', fn() => User::factory()->for($this->otherTenant)->create());

    beforeEach(function () {
        mockExternalApis();
    });

    describe('GET /api/projects', function () {
        it('returns authenticated user projects', function () {
            $projects = Project::factory()->count(3)->for($this->tenant)->create();
            
            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects');

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'domain',
                            'description',
                            'status',
                            'created_at',
                            'keywords_count',
                            'competitors_count'
                        ]
                    ],
                    'links',
                    'meta'
                ]);
        });

        it('filters projects by search term', function () {
            Project::factory()->for($this->tenant)->create(['name' => 'SEO Project Alpha']);
            Project::factory()->for($this->tenant)->create(['name' => 'Marketing Project Beta']);
            Project::factory()->for($this->tenant)->create(['domain' => 'seo-site.com']);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects?search=seo');

            $response->assertOk()
                ->assertJsonCount(2, 'data');
        });

        it('filters projects by status', function () {
            Project::factory()->for($this->tenant)->create(['status' => 'active']);
            Project::factory()->for($this->tenant)->create(['status' => 'archived']);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects?status=active');

            $response->assertOk()
                ->assertJsonCount(1, 'data');
        });

        it('sorts projects by specified field', function () {
            $project1 = Project::factory()->for($this->tenant)->create(['name' => 'Alpha Project']);
            $project2 = Project::factory()->for($this->tenant)->create(['name' => 'Beta Project']);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects?sort=name&direction=asc');

            $response->assertOk();
            
            $data = $response->json('data');
            expect($data[0]['name'])->toBe('Alpha Project');
            expect($data[1]['name'])->toBe('Beta Project');
        });

        it('paginates results', function () {
            Project::factory()->count(20)->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects?per_page=10');

            $response->assertOk()
                ->assertJsonCount(10, 'data')
                ->assertJsonPath('meta.per_page', 10);
        });

        it('does not return other tenant projects', function () {
            Project::factory()->count(2)->for($this->tenant)->create();
            Project::factory()->count(3)->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects');

            $response->assertOk()
                ->assertJsonCount(2, 'data');
        });

        it('requires authentication', function () {
            $response = $this->getJson('/api/projects');

            $response->assertUnauthorized();
        });
    });

    describe('POST /api/projects', function () {
        it('creates a new project with valid data', function () {
            $projectData = [
                'name' => 'New SEO Project',
                'domain' => 'example.com',
                'description' => 'A test project',
                'target_location' => 'United States',
                'target_language' => 'en',
                'settings' => ['tracking_enabled' => true]
            ];

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', $projectData);

            $response->assertCreated()
                ->assertJsonFragment([
                    'name' => 'New SEO Project',
                    'domain' => 'example.com'
                ]);

            $this->assertDatabaseHas('projects', [
                'tenant_id' => $this->tenant->id,
                'name' => 'New SEO Project',
                'domain' => 'example.com'
            ]);
        });

        it('validates required fields', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'domain']);
        });

        it('validates domain format', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', [
                    'name' => 'Test Project',
                    'domain' => 'invalid-domain-format'
                ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors('domain');
        });

        it('creates project with default values', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', [
                    'name' => 'Minimal Project',
                    'domain' => 'minimal.com'
                ]);

            $response->assertCreated();
            
            $project = Project::where('name', 'Minimal Project')->first();
            expect($project->status)->toBe('active');
            expect($project->target_location)->toBe('United States');
            expect($project->target_language)->toBe('en');
        });

        it('associates project with authenticated user tenant', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', [
                    'name' => 'Tenant Project',
                    'domain' => 'tenant.com'
                ]);

            $response->assertCreated();
            
            $project = Project::where('name', 'Tenant Project')->first();
            expect($project->tenant_id)->toBe($this->tenant->id);
        });

        it('requires authentication', function () {
            $response = $this->postJson('/api/projects', [
                'name' => 'Unauthorized Project',
                'domain' => 'unauthorized.com'
            ]);

            $response->assertUnauthorized();
        });
    });

    describe('GET /api/projects/{project}', function () {
        it('returns project with relationships', function () {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(3)->for($project)->for($this->tenant)->create();
            $competitor = Competitor::factory()->for($project)->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}");

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'name',
                        'domain',
                        'keywords' => [
                            '*' => [
                                'id',
                                'keyword',
                                'current_position',
                                'positions'
                            ]
                        ],
                        'competitors' => [
                            '*' => [
                                'id',
                                'name',
                                'domain'
                            ]
                        ],
                        'reports'
                    ]
                ]);
        });

        it('returns 404 for non-existent project', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson('/api/projects/99999');

            $response->assertNotFound();
        });

        it('prevents access to other tenant projects', function () {
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$otherProject->id}");

            $response->assertForbidden();
        });

        it('requires authentication', function () {
            $project = Project::factory()->for($this->tenant)->create();

            $response = $this->getJson("/api/projects/{$project->id}");

            $response->assertUnauthorized();
        });
    });

    describe('PUT /api/projects/{project}', function () {
        it('updates project with valid data', function () {
            $project = Project::factory()->for($this->tenant)->create();

            $updateData = [
                'name' => 'Updated Project Name',
                'description' => 'Updated description',
                'status' => 'paused'
            ];

            $response = $this->actingAs($this->user, 'sanctum')
                ->putJson("/api/projects/{$project->id}", $updateData);

            $response->assertOk()
                ->assertJsonFragment([
                    'name' => 'Updated Project Name',
                    'description' => 'Updated description',
                    'status' => 'paused'
                ]);

            $project->refresh();
            expect($project->name)->toBe('Updated Project Name');
        });

        it('validates update data', function () {
            $project = Project::factory()->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->putJson("/api/projects/{$project->id}", [
                    'domain' => 'invalid-domain'
                ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors('domain');
        });

        it('prevents updating other tenant projects', function () {
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->putJson("/api/projects/{$otherProject->id}", [
                    'name' => 'Hacked Project'
                ]);

            $response->assertForbidden();
        });

        it('returns 404 for non-existent project', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->putJson('/api/projects/99999', ['name' => 'Test']);

            $response->assertNotFound();
        });
    });

    describe('DELETE /api/projects/{project}', function () {
        it('deletes project successfully', function () {
            $project = Project::factory()->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->deleteJson("/api/projects/{$project->id}");

            $response->assertOk()
                ->assertJsonFragment(['message' => 'Project deleted successfully']);

            $this->assertSoftDeleted($project);
        });

        it('prevents deleting other tenant projects', function () {
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->deleteJson("/api/projects/{$otherProject->id}");

            $response->assertForbidden();
        });

        it('returns 404 for non-existent project', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->deleteJson('/api/projects/99999');

            $response->assertNotFound();
        });
    });

    describe('GET /api/projects/{project}/dashboard', function () {
        it('returns comprehensive dashboard data', function () {
            $project = Project::factory()->for($this->tenant)->create();
            $keywords = Keyword::factory()->count(5)->for($project)->for($this->tenant)->create();
            
            // Create some position history
            foreach ($keywords as $keyword) {
                KeywordPosition::factory()->count(3)->for($keyword)->for($this->tenant)->create();
            }

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/dashboard");

            $response->assertOk()
                ->assertJsonStructure([
                    'project',
                    'metrics' => [
                        'visibility_score',
                        'traffic_potential'
                    ],
                    'recent_activity' => [
                        'position_changes',
                        'new_rankings',
                        'lost_rankings'
                    ],
                    'charts' => [
                        'position_trends',
                        'visibility_history'
                    ]
                ]);
        });

        it('calculates metrics correctly', function () {
            $project = Project::factory()->for($this->tenant)->create();
            
            // Create keyword with known position and volume
            $keyword = Keyword::factory()->for($project)->for($this->tenant)->create([
                'current_position' => 1,
                'search_volume' => 1000
            ]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/dashboard");

            $response->assertOk();
            
            $metrics = $response->json('metrics');
            expect($metrics['visibility_score'])->toBe(31.7); // Position 1 CTR
        });

        it('prevents access to other tenant projects', function () {
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$otherProject->id}/dashboard");

            $response->assertForbidden();
        });
    });

    describe('GET /api/projects/{project}/analytics', function () {
        it('returns analytics data for date range', function () {
            $project = Project::factory()->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/analytics?start_date=2024-01-01&end_date=2024-01-31");

            $response->assertOk()
                ->assertJsonStructure([
                    'keyword_traffic'
                ]);
        });

        it('includes search console data when configured', function () {
            $project = Project::factory()->for($this->tenant)->create([
                'gsc_property_url' => 'https://example.com'
            ]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/analytics");

            $response->assertOk()
                ->assertJsonStructure([
                    'search_console',
                    'keyword_traffic'
                ]);
        });

        it('includes analytics data when configured', function () {
            $project = Project::factory()->for($this->tenant)->create([
                'ga4_property_id' => 'GA4-12345'
            ]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/analytics");

            $response->assertOk()
                ->assertJsonStructure([
                    'google_analytics',
                    'keyword_traffic'
                ]);
        });
    });

    describe('POST /api/projects/bulk-update', function () {
        it('updates multiple projects successfully', function () {
            $projects = Project::factory()->count(3)->for($this->tenant)->create();
            $projectIds = $projects->pluck('id')->toArray();

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects/bulk-update', [
                    'project_ids' => $projectIds,
                    'updates' => ['status' => 'paused']
                ]);

            $response->assertOk()
                ->assertJsonFragment(['updated_count' => 3]);

            foreach ($projects as $project) {
                $project->refresh();
                expect($project->status)->toBe('paused');
            }
        });

        it('validates project IDs exist', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects/bulk-update', [
                    'project_ids' => [99999],
                    'updates' => ['status' => 'paused']
                ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors('project_ids.0');
        });

        it('only updates tenant-owned projects', function () {
            $tenantProject = Project::factory()->for($this->tenant)->create();
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects/bulk-update', [
                    'project_ids' => [$tenantProject->id, $otherProject->id],
                    'updates' => ['status' => 'paused']
                ]);

            $response->assertOk()
                ->assertJsonFragment(['updated_count' => 1]);

            $tenantProject->refresh();
            $otherProject->refresh();
            
            expect($tenantProject->status)->toBe('paused');
            expect($otherProject->status)->not->toBe('paused');
        });
    });

    describe('POST /api/projects/{project}/archive', function () {
        it('archives active project', function () {
            $project = Project::factory()->for($this->tenant)->create(['status' => 'active']);

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/projects/{$project->id}/archive");

            $response->assertOk()
                ->assertJsonFragment(['message' => 'Project archived successfully']);

            $project->refresh();
            expect($project->status)->toBe('archived');
        });

        it('restores archived project', function () {
            $project = Project::factory()->for($this->tenant)->create(['status' => 'archived']);

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/projects/{$project->id}/archive");

            $response->assertOk()
                ->assertJsonFragment(['message' => 'Project restored successfully']);

            $project->refresh();
            expect($project->status)->toBe('active');
        });

        it('prevents archiving other tenant projects', function () {
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson("/api/projects/{$otherProject->id}/archive");

            $response->assertForbidden();
        });
    });

    describe('GET /api/projects/{project}/competitors', function () {
        it('returns competitor data with metrics', function () {
            $project = Project::factory()->for($this->tenant)->create();
            $competitor = Competitor::factory()->for($project)->for($this->tenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/competitors");

            $response->assertOk()
                ->assertJsonStructure([
                    '*' => [
                        'id',
                        'name',
                        'domain',
                        'average_position',
                        'total_keywords',
                        'last_updated',
                        'trend'
                    ]
                ]);
        });

        it('calculates competitor metrics correctly', function () {
            $project = Project::factory()->for($this->tenant)->create();
            $competitor = Competitor::factory()->for($project)->for($this->tenant)->create();

            // Create keyword positions for competitor analysis
            $keyword = Keyword::factory()->for($project)->for($this->tenant)->create();
            KeywordPosition::factory()->count(3)->for($keyword)->for($this->tenant)->create([
                'position' => 5
            ]);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}/competitors");

            $response->assertOk();
            
            $competitorData = $response->json();
            expect($competitorData[0]['total_keywords'])->toBeGreaterThan(0);
        });

        it('prevents access to other tenant projects', function () {
            $otherProject = Project::factory()->for($this->otherTenant)->create();

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$otherProject->id}/competitors");

            $response->assertForbidden();
        });
    });

    describe('Rate Limiting and Performance', function () {
        it('handles concurrent requests efficiently', function () {
            $project = Project::factory()->for($this->tenant)->create();

            // Test with performance assertion
            $this->assertResponseTimeLessThan(2000, function () use ($project) {
                $responses = [];
                for ($i = 0; $i < 5; $i++) {
                    $responses[] = $this->actingAs($this->user, 'sanctum')
                        ->getJson("/api/projects/{$project->id}");
                }
                
                foreach ($responses as $response) {
                    $response->assertOk();
                }
            });
        });

        it('optimizes database queries for large datasets', function () {
            $project = Project::factory()->for($this->tenant)->create();
            
            // Create large dataset
            Keyword::factory()->count(50)->for($project)->for($this->tenant)->create();

            // Test query efficiency
            $this->assertQueryCountLessThan(20, function () use ($project) {
                $this->actingAs($this->user, 'sanctum')
                    ->getJson("/api/projects/{$project->id}");
            });
        });
    });

    describe('Error Handling', function () {
        it('handles database errors gracefully', function () {
            $project = Project::factory()->for($this->tenant)->create();
            
            // Mock database error scenario
            \DB::shouldReceive('table')->andThrow(new \Exception('Database connection failed'));

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects/{$project->id}");

            // In a real scenario, this should return a 500 or be handled gracefully
            expect($response->status())->toBeIn([500, 503]);
        });

        it('validates malformed JSON requests', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', '{"invalid": json}');

            $response->assertStatus(400);
        });
    });

    describe('Data Integrity and Validation', function () {
        it('prevents SQL injection in search parameters', function () {
            $project = Project::factory()->for($this->tenant)->create(['name' => 'Normal Project']);

            $response = $this->actingAs($this->user, 'sanctum')
                ->getJson("/api/projects?search='; DROP TABLE projects; --");

            $response->assertOk();
            
            // Verify project still exists
            $this->assertDatabaseHas('projects', ['id' => $project->id]);
        });

        it('sanitizes input data properly', function () {
            $response = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/projects', [
                    'name' => '<script>alert("xss")</script>Test Project',
                    'domain' => 'test.com',
                    'description' => '<b>Bold</b> description'
                ]);

            $response->assertCreated();
            
            $project = Project::where('domain', 'test.com')->first();
            expect($project->name)->not->toContain('<script>');
        });
    });
});