<?php

declare(strict_types=1);

use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

describe('Project Model', function (): void {

    describe('Factory and Creation', function (): void {
        it('can be created with valid data', function (): void {
            $tenant = Tenant::factory()->create();
            $user = User::factory()->for($tenant)->create();
            $project = Project::factory()->for($user)->for($tenant)->create();

            expect($project)->toBeInstanceOf(Project::class);
            expect($project->tenant_id)->toBe($tenant->id);
            expect($project->user_id)->toBe($user->id);
            expect($project->url)->toBeValidUrl();
            expect($project->is_active)->toBeTrue();
        });

        it('extracts domain from URL automatically', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create([
                'url' => 'https://example.com/path',
                'domain' => null,
            ]);

            expect($project->domain)->toBe('example.com');
        });

        it('can be created with specific domain', function (): void {
            $domain = 'testsite.com';
            $project = Project::factory()->withDomain($domain)->create();

            expect($project->domain)->toBe($domain);
            expect($project->url)->toBe('https://'.$domain);
        });

        it('can be created for specific countries', function (): void {
            $countries = ['US', 'GB', 'CA'];
            $project = Project::factory()->forCountries($countries)->create();

            expect($project->target_countries)->toBe($countries);
        });
    });

    describe('Relationships', function (): void {
        it('belongs to a user', function (): void {
            $user = User::factory()->create();
            $project = Project::factory()->for($user)->for($user->tenant)->create();

            expect($project->user)->toBeInstanceOf(User::class);
            expect($project->user->id)->toBe($user->id);
        });

        it('has many keywords', function (): void {
            $project = Project::factory()->create();
            $keywords = Keyword::factory()->count(5)->for($project)->for($project->tenant)->create();

            expect($project->keywords)->toHaveCount(5);
            expect($project->keywords->first())->toBeInstanceOf(Keyword::class);
        });

        it('has many competitors', function (): void {
            $project = Project::factory()->create();
            $competitors = Competitor::factory()->count(3)->for($project)->for($project->tenant)->create();

            expect($project->competitors)->toHaveCount(3);
            expect($project->competitors->first())->toBeInstanceOf(Competitor::class);
        });

        it('has many reports', function (): void {
            $project = Project::factory()->create();
            $reports = Report::factory()->count(2)->for($project)->for($project->tenant)->create();

            expect($project->reports)->toHaveCount(2);
            expect($project->reports->first())->toBeInstanceOf(Report::class);
        });

        it('has many notifications', function (): void {
            $project = Project::factory()->create();
            $notifications = Notification::factory()->count(4)->for($project)->for($project->tenant)->create();

            expect($project->notifications)->toHaveCount(4);
            expect($project->notifications->first())->toBeInstanceOf(Notification::class);
        });
    });

    describe('Scopes', function (): void {
        it('scopes active projects', function (): void {
            $activeProject = Project::factory()->create(['is_active' => true]);
            $inactiveProject = Project::factory()->create(['is_active' => false]);

            $activeProjects = Project::active()->get();

            expect($activeProjects)->toContain($activeProject);
            expect($activeProjects)->not->toContain($inactiveProject);
        });

        it('scopes projects by domain', function (): void {
            $domain = 'example.com';
            $project1 = Project::factory()->withDomain($domain)->create();
            $project2 = Project::factory()->withDomain('other.com')->create();

            $domainProjects = Project::forDomain($domain)->get();

            expect($domainProjects)->toContain($project1);
            expect($domainProjects)->not->toContain($project2);
        });
    });

    describe('Analytics Methods', function (): void {
        it('counts total keywords correctly', function (): void {
            $project = Project::factory()->create();
            Keyword::factory()->count(10)->for($project)->for($project->tenant)->create();

            expect($project->getTotalKeywords())->toBe(10);
        });

        it('counts active keywords correctly', function (): void {
            $project = Project::factory()->create();

            // Create 7 active keywords and 3 inactive
            Keyword::factory()->count(7)->for($project)->for($project->tenant)->create(['is_tracking_active' => true]);
            Keyword::factory()->count(3)->for($project)->for($project->tenant)->create(['is_tracking_active' => false]);

            expect($project->getActiveKeywords())->toBe(7);
        });

        it('counts top 10 keywords correctly', function (): void {
            $project = Project::factory()->create();

            // Create keywords with different positions
            Keyword::factory()->count(5)->for($project)->for($project->tenant)->create(['current_position' => 5]);
            Keyword::factory()->count(3)->for($project)->for($project->tenant)->create(['current_position' => 15]);
            Keyword::factory()->count(2)->for($project)->for($project->tenant)->create(['current_position' => 50]);

            expect($project->getTop10Keywords())->toBe(5);
        });

        it('calculates average position correctly', function (): void {
            $project = Project::factory()->create();

            // Create keywords with specific positions: 2, 4, 6 (average should be 4.0)
            Keyword::factory()->for($project)->for($project->tenant)->create(['current_position' => 2]);
            Keyword::factory()->for($project)->for($project->tenant)->create(['current_position' => 4]);
            Keyword::factory()->for($project)->for($project->tenant)->create(['current_position' => 6]);

            // Add one keyword with null position (should be ignored)
            Keyword::factory()->for($project)->for($project->tenant)->create(['current_position' => null]);

            expect($project->getAveragePosition())->toBe(4.0);
        });

        it('handles empty keywords for average position', function (): void {
            $project = Project::factory()->create();

            expect($project->getAveragePosition())->toBe(0.0);
        });
    });

    describe('Position Update Logic', function (): void {
        it('determines if needs position update correctly', function (): void {
            // Project never updated
            $project1 = Project::factory()->create(['last_positions_updated_at' => null]);
            expect($project1->needsPositionUpdate())->toBeTrue();

            // Project updated recently (within 24 hours)
            $project2 = Project::factory()->create(['last_positions_updated_at' => now()->subHours(12)]);
            expect($project2->needsPositionUpdate())->toBeFalse();

            // Project updated more than 24 hours ago
            $project3 = Project::factory()->create(['last_positions_updated_at' => now()->subHours(25)]);
            expect($project3->needsPositionUpdate())->toBeTrue();
        });
    });

    describe('Visibility Score Calculation', function (): void {
        it('calculates visibility score correctly', function (): void {
            $project = Project::factory()->create();

            // Top 3 positions (1.0 each) = 2.0
            Keyword::factory()->count(2)->for($project)->for($project->tenant)->create(['current_position' => 1]);

            // Positions 4-10 (0.5 each) = 1.0
            Keyword::factory()->count(2)->for($project)->for($project->tenant)->create(['current_position' => 8]);

            // Positions 11-20 (0.1 each) = 0.2
            Keyword::factory()->count(2)->for($project)->for($project->tenant)->create(['current_position' => 15]);

            // Beyond position 20 (0 each) = 0
            Keyword::factory()->count(2)->for($project)->for($project->tenant)->create(['current_position' => 50]);

            // Total: 3.2 points out of 8 keywords = 40%
            $expectedScore = (3.2 / 8) * 100; // 40.0

            expect($project->getVisibilityScore())->toBe(40.0);
        });

        it('handles no keywords for visibility score', function (): void {
            $project = Project::factory()->create();

            expect($project->getVisibilityScore())->toBe(0.0);
        });

        it('ignores null positions in visibility calculation', function (): void {
            $project = Project::factory()->create();

            Keyword::factory()->for($project)->for($project->tenant)->create(['current_position' => 1]); // 1.0 point
            Keyword::factory()->for($project)->for($project->tenant)->create(['current_position' => null]); // ignored

            // Should calculate based only on non-null positions
            expect($project->getVisibilityScore())->toBe(100.0);
        });
    });

    describe('Casts and Attributes', function (): void {
        it('casts attributes correctly', function (): void {
            $project = Project::factory()->create([
                'target_countries' => ['US', 'GB'],
                'target_languages' => ['en'],
                'search_engines' => ['google', 'bing'],
                'devices' => ['desktop', 'mobile'],
                'integrations' => ['google_search_console' => true],
                'settings' => ['frequency' => 'daily'],
                'is_active' => '1',
                'last_crawled_at' => '2024-01-01 12:00:00',
                'last_positions_updated_at' => '2024-01-01 13:00:00',
            ]);

            expect($project->target_countries)->toBeArray();
            expect($project->target_languages)->toBeArray();
            expect($project->search_engines)->toBeArray();
            expect($project->devices)->toBeArray();
            expect($project->integrations)->toBeArray();
            expect($project->settings)->toBeArray();
            expect($project->is_active)->toBeTrue();
            expect($project->last_crawled_at)->toBeInstanceOf(Carbon::class);
            expect($project->last_positions_updated_at)->toBeInstanceOf(Carbon::class);
        });
    });

    describe('Factory States', function (): void {
        it('creates inactive projects', function (): void {
            $project = Project::factory()->inactive()->create();

            expect($project->is_active)->toBeFalse();
        });

        it('creates projects that need update', function (): void {
            $project = Project::factory()->needsUpdate()->create();

            expect($project->needsPositionUpdate())->toBeTrue();
        });

        it('creates recently updated projects', function (): void {
            $project = Project::factory()->recentlyUpdated()->create();

            expect($project->needsPositionUpdate())->toBeFalse();
        });

        it('creates projects with integrations enabled', function (): void {
            $project = Project::factory()->withIntegrations()->create();

            expect($project->integrations['google_search_console'])->toBeTrue();
            expect($project->integrations['google_analytics'])->toBeTrue();
            expect($project->integrations['google_ads'])->toBeTrue();
        });
    });

    describe('Business Logic Edge Cases', function (): void {
        it('handles missing domain gracefully', function (): void {
            $project = Project::factory()->create(['domain' => null, 'url' => null]);

            expect($project->domain)->toBeNull();
        });

        it('handles malformed URLs gracefully', function (): void {
            // This tests the boot method's URL parsing
            $project = Project::factory()->make(['url' => 'not-a-url', 'domain' => null]);
            $project->save();

            // Domain should remain null for malformed URLs
            expect($project->domain)->toBeNull();
        });
    });

    describe('Tenant Isolation', function (): void {
        it('belongs to correct tenant', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();

            expect($project->tenant_id)->toBe($tenant->id);
        });

        it('maintains tenant relationships through keywords', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $keyword = Keyword::factory()->for($project)->for($tenant)->create();

            expect($keyword->tenant_id)->toBe($tenant->id);
            expect($keyword->project->tenant_id)->toBe($tenant->id);
        });
    });
});
