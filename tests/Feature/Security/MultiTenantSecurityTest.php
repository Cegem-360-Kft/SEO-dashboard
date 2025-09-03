<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;

describe('Multi-Tenant Security and Data Isolation', function (): void {
    let('tenantA', fn () => Tenant::factory()->create(['name' => 'Tenant A']));
    let('tenantB', fn () => Tenant::factory()->create(['name' => 'Tenant B']));
    let('userA', fn () => User::factory()->for($this->tenantA)->create());
    let('userB', fn () => User::factory()->for($this->tenantB)->create());

    beforeEach(function (): void {
        mockExternalApis();
    });

    describe('Project Data Isolation', function (): void {
        it('prevents access to other tenant projects via API', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            // User A should access their project
            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects/'.$projectA->id);
            $response->assertOk();

            // User A should NOT access tenant B's project
            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects/'.$projectB->id);
            $response->assertForbidden();
        });

        it('filters project listings by tenant', function (): void {
            Project::factory()->count(3)->for($this->tenantA)->create();
            Project::factory()->count(5)->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects');

            $response->assertOk()
                ->assertJsonCount(3, 'data');

            $response = $this->actingAs($this->userB, 'sanctum')
                ->getJson('/api/projects');

            $response->assertOk()
                ->assertJsonCount(5, 'data');
        });

        it('prevents project modification across tenants', function (): void {
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->putJson('/api/projects/'.$projectB->id, [
                    'name' => 'Hacked Project Name',
                ]);

            $response->assertForbidden();

            $projectB->refresh();
            expect($projectB->name)->not->toBe('Hacked Project Name');
        });

        it('prevents project deletion across tenants', function (): void {
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->deleteJson('/api/projects/'.$projectB->id);

            $response->assertForbidden();

            expect(Project::query()->find($projectB->id))->not->toBeNull();
        });
    });

    describe('Keyword Data Isolation', function (): void {
        it('isolates keywords by tenant', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $keywordsA = Keyword::factory()->count(3)->for($projectA)->for($this->tenantA)->create();
            $keywordsB = Keyword::factory()->count(2)->for($projectB)->for($this->tenantB)->create();

            // Verify database level isolation
            $tenantAKeywords = Keyword::query()->where('tenant_id', $this->tenantA->id)->count();
            $tenantBKeywords = Keyword::query()->where('tenant_id', $this->tenantB->id)->count();

            expect($tenantAKeywords)->toBe(3);
            expect($tenantBKeywords)->toBe(2);
        });

        it('prevents keyword access across tenant projects', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $keywordA = Keyword::factory()->for($projectA)->for($this->tenantA)->create();
            $keywordB = Keyword::factory()->for($projectB)->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/keywords/'.$keywordB->id);

            $response->assertForbidden();
        });

        it('prevents keyword creation in other tenant projects', function (): void {
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->postJson('/api/keywords', [
                    'project_id' => $projectB->id,
                    'keyword' => 'hacker keyword',
                    'country' => 'US',
                    'language' => 'en',
                ]);

            $response->assertForbidden();

            $this->assertDatabaseMissing('keywords', [
                'keyword' => 'hacker keyword',
                'project_id' => $projectB->id,
            ]);
        });
    });

    describe('Position Data Isolation', function (): void {
        it('isolates keyword positions by tenant', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $keywordA = Keyword::factory()->for($projectA)->for($this->tenantA)->create();
            $keywordB = Keyword::factory()->for($projectB)->for($this->tenantB)->create();

            $positionsA = KeywordPosition::factory()->count(5)->for($keywordA)->for($this->tenantA)->create();
            $positionsB = KeywordPosition::factory()->count(3)->for($keywordB)->for($this->tenantB)->create();

            $tenantAPositions = KeywordPosition::query()->where('tenant_id', $this->tenantA->id)->count();
            $tenantBPositions = KeywordPosition::query()->where('tenant_id', $this->tenantB->id)->count();

            expect($tenantAPositions)->toBe(5);
            expect($tenantBPositions)->toBe(3);
        });

        it('prevents position data leakage in API responses', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $keywordA = Keyword::factory()->for($projectA)->for($this->tenantA)->create();
            $keywordB = Keyword::factory()->for($projectB)->for($this->tenantB)->create();

            KeywordPosition::factory()->for($keywordA)->for($this->tenantA)->create(['position' => 1]);
            KeywordPosition::factory()->for($keywordB)->for($this->tenantB)->create(['position' => 2]);

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects/'.$projectA->id);

            $response->assertOk();

            $keywords = $response->json('data.keywords');
            $positions = collect($keywords)->flatMap(fn ($k): mixed => $k['positions'] ?? []);

            // Verify only tenant A positions are returned
            foreach ($positions as $position) {
                expect($position['tenant_id'] ?? null)->toBe($this->tenantA->id);
            }
        });
    });

    describe('Competitor Data Isolation', function (): void {
        it('isolates competitors by tenant', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $competitorsA = Competitor::factory()->count(2)->for($projectA)->for($this->tenantA)->create();
            $competitorsB = Competitor::factory()->count(3)->for($projectB)->for($this->tenantB)->create();

            $tenantACompetitors = Competitor::query()->where('tenant_id', $this->tenantA->id)->count();
            $tenantBCompetitors = Competitor::query()->where('tenant_id', $this->tenantB->id)->count();

            expect($tenantACompetitors)->toBe(2);
            expect($tenantBCompetitors)->toBe(3);
        });

        it('prevents competitor access across tenants', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $competitorB = Competitor::factory()->for($projectB)->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/competitors/'.$competitorB->id);

            $response->assertForbidden();
        });
    });

    describe('Report Data Isolation', function (): void {
        it('isolates reports by tenant', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $reportsA = Report::factory()->count(3)->for($projectA)->for($this->userA)->for($this->tenantA)->create();
            $reportsB = Report::factory()->count(4)->for($projectB)->for($this->userB)->for($this->tenantB)->create();

            $tenantAReports = Report::query()->where('tenant_id', $this->tenantA->id)->count();
            $tenantBReports = Report::query()->where('tenant_id', $this->tenantB->id)->count();

            expect($tenantAReports)->toBe(3);
            expect($tenantBReports)->toBe(4);
        });

        it('prevents report access across tenants', function (): void {
            $projectB = Project::factory()->for($this->tenantB)->create();
            $reportB = Report::factory()->for($projectB)->for($this->userB)->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/reports/'.$reportB->id);

            $response->assertForbidden();
        });

        it('prevents report generation for other tenant projects', function (): void {
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->postJson('/api/reports', [
                    'project_id' => $projectB->id,
                    'type' => 'positions',
                    'name' => 'Unauthorized Report',
                ]);

            $response->assertForbidden();
        });
    });

    describe('User Data Isolation', function (): void {
        it('isolates users by tenant in management operations', function (): void {
            $userA2 = User::factory()->for($this->tenantA)->create();
            $userB2 = User::factory()->for($this->tenantB)->create();

            // Admin user A should only see tenant A users
            $adminA = User::factory()->for($this->tenantA)->admin()->create();

            $response = $this->actingAs($adminA, 'sanctum')
                ->getJson('/api/users');

            $response->assertOk();

            $userIds = collect($response->json('data'))->pluck('id');
            expect($userIds)->toContain($this->userA->id, $adminA->id, $userA2->id);
            expect($userIds)->not->toContain($this->userB->id, $userB2->id);
        });

        it('prevents user modification across tenants', function (): void {
            $response = $this->actingAs($this->userA, 'sanctum')
                ->putJson('/api/users/'.$this->userB->id, [
                    'name' => 'Hacked User Name',
                ]);

            $response->assertForbidden();

            $this->userB->refresh();
            expect($this->userB->name)->not->toBe('Hacked User Name');
        });
    });

    describe('Notification Data Isolation', function (): void {
        it('isolates notifications by tenant', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $notificationsA = Notification::factory()->count(5)->for($this->userA)->for($projectA)->for($this->tenantA)->create();
            $notificationsB = Notification::factory()->count(3)->for($this->userB)->for($projectB)->for($this->tenantB)->create();

            $tenantANotifications = Notification::query()->where('tenant_id', $this->tenantA->id)->count();
            $tenantBNotifications = Notification::query()->where('tenant_id', $this->tenantB->id)->count();

            expect($tenantANotifications)->toBe(5);
            expect($tenantBNotifications)->toBe(3);
        });

        it('filters user notifications by tenant', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            Notification::factory()->count(3)->for($this->userA)->for($projectA)->for($this->tenantA)->create();
            Notification::factory()->count(2)->for($this->userB)->for($projectB)->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/notifications');

            $response->assertOk()
                ->assertJsonCount(3, 'data');
        });
    });

    describe('Audit Log Data Isolation', function (): void {
        it('isolates audit logs by tenant', function (): void {
            // Create audit logs for each tenant
            AuditLog::factory()->count(4)->for($this->userA)->create(['tenant_id' => $this->tenantA->id]);
            AuditLog::factory()->count(6)->for($this->userB)->create(['tenant_id' => $this->tenantB->id]);

            $tenantALogs = AuditLog::query()->where('tenant_id', $this->tenantA->id)->count();
            $tenantBLogs = AuditLog::query()->where('tenant_id', $this->tenantB->id)->count();

            expect($tenantALogs)->toBe(4);
            expect($tenantBLogs)->toBe(6);
        });

        it('prevents audit log access across tenants', function (): void {
            $logB = AuditLog::factory()->for($this->userB)->create(['tenant_id' => $this->tenantB->id]);

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/audit-logs/'.$logB->id);

            $response->assertForbidden();
        });
    });

    describe('Direct Database Access Security', function (): void {
        it('ensures tenant_id is always set on creation', function (): void {
            // Test that creating records without tenant_id fails
            expect(function (): void {
                Project::query()->create([
                    'name' => 'Test Project',
                    'domain' => 'test.com',
                    // Missing tenant_id
                ]);
            })->toThrow(Exception::class);
        });

        it('prevents mass assignment of tenant_id', function (): void {
            $project = Project::factory()->for($this->tenantA)->create();

            // Attempt to change tenant_id via mass assignment
            $project->update(['tenant_id' => $this->tenantB->id]);

            // Should remain with original tenant
            expect($project->fresh()->tenant_id)->toBe($this->tenantA->id);
        });

        it('validates tenant relationships in model constraints', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();

            // Try to create keyword with mismatched tenant
            expect(function () use ($projectA): void {
                Keyword::query()->create([
                    'tenant_id' => $this->tenantB->id, // Different tenant
                    'project_id' => $projectA->id,     // Tenant A project
                    'keyword' => 'test keyword',
                    'country' => 'US',
                    'language' => 'en',
                ]);
            })->toThrow(Exception::class);
        });
    });

    describe('API Security Headers and CORS', function (): void {
        it('includes security headers in API responses', function (): void {
            $project = Project::factory()->for($this->tenantA)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects/'.$project->id);

            $response->assertOk();

            // Check for security headers
            expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
            expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
        });

        it('prevents CSRF attacks on state-changing operations', function (): void {
            $project = Project::factory()->for($this->tenantA)->create();

            // Attempt request without CSRF token (simulated)
            $response = $this->postJson('/api/projects/'.$project->id, [
                'name' => 'Updated Name',
            ]);

            $response->assertUnauthorized();
        });
    });

    describe('Session and Token Security', function (): void {
        it('invalidates tokens on tenant switch attempts', function (): void {
            // Create token for user A
            $token = $this->userA->createToken('test-token');

            // Attempt to access tenant B data with user A token
            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$token->plainTextToken,
            ])->getJson('/api/projects');

            $response->assertOk();

            // Verify only tenant A data is accessible
            $projects = $response->json('data');
            foreach ($projects as $project) {
                expect($project['tenant_id'])->toBe($this->tenantA->id);
            }
        });

        it('prevents token reuse across different tenants', function (): void {
            $tokenA = $this->userA->createToken('tenant-a-token');

            // Use tenant A token to try accessing tenant B data
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->withHeaders([
                'Authorization' => 'Bearer '.$tokenA->plainTextToken,
            ])->getJson('/api/projects/'.$projectB->id);

            $response->assertForbidden();
        });
    });

    describe('Error Information Disclosure', function (): void {
        it('does not leak tenant information in error messages', function (): void {
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects/'.$projectB->id);

            $response->assertForbidden();

            // Error message should not contain tenant B information
            $errorMessage = $response->json('message') ?? '';
            expect($errorMessage)->not->toContain($this->tenantB->name);
            expect($errorMessage)->not->toContain($this->tenantB->uuid);
        });

        it('handles non-existent resources without leaking information', function (): void {
            $response = $this->actingAs($this->userA, 'sanctum')
                ->getJson('/api/projects/99999');

            $response->assertNotFound();

            // Should not indicate whether resource exists in another tenant
            $errorMessage = $response->json('message') ?? '';
            expect($errorMessage)->not->toContain('tenant');
        });
    });

    describe('Performance Under Multi-Tenancy', function (): void {
        it('maintains performance with proper tenant scoping', function (): void {
            // Create large datasets for both tenants
            $projectsA = Project::factory()->count(50)->for($this->tenantA)->create();
            $projectsB = Project::factory()->count(50)->for($this->tenantB)->create();

            foreach ($projectsA->take(10) as $project) {
                Keyword::factory()->count(20)->for($project)->for($this->tenantA)->create();
            }

            foreach ($projectsB->take(10) as $project) {
                Keyword::factory()->count(20)->for($project)->for($this->tenantB)->create();
            }

            // Test query performance with tenant scoping
            $this->assertQueryCountLessThan(10, function (): void {
                $this->actingAs($this->userA, 'sanctum')
                    ->getJson('/api/projects?per_page=10');
            });
        });
    });

    describe('Bulk Operations Security', function (): void {
        it('prevents bulk operations across tenants', function (): void {
            $projectA = Project::factory()->for($this->tenantA)->create();
            $projectB = Project::factory()->for($this->tenantB)->create();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->postJson('/api/projects/bulk-update', [
                    'project_ids' => [$projectA->id, $projectB->id],
                    'updates' => ['status' => 'archived'],
                ]);

            // Should only update tenant A project
            $response->assertOk()
                ->assertJsonFragment(['updated_count' => 1]);

            $projectA->refresh();
            $projectB->refresh();

            expect($projectA->status)->toBe('archived');
            expect($projectB->status)->not->toBe('archived');
        });

        it('validates bulk operation limits per tenant', function (): void {
            $projects = Project::factory()->count(100)->for($this->tenantA)->create();
            $projectIds = $projects->pluck('id')->toArray();

            $response = $this->actingAs($this->userA, 'sanctum')
                ->postJson('/api/projects/bulk-update', [
                    'project_ids' => $projectIds,
                    'updates' => ['status' => 'paused'],
                ]);

            // Should enforce bulk operation limits
            $response->assertUnprocessable()
                ->assertJsonValidationErrors('project_ids');
        });
    });
});
