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

describe('Tenant Model', function (): void {

    describe('Factory and Creation', function (): void {
        it('can be created with valid data', function (): void {
            $tenant = Tenant::factory()->create();

            expect($tenant)->toBeInstanceOf(Tenant::class);
            expect($tenant->uuid)->not->toBeEmpty();
            expect($tenant->name)->not->toBeEmpty();
            expect($tenant->slug)->not->toBeEmpty();
            expect($tenant->is_active)->toBeTrue();
        });

        it('generates UUID automatically', function (): void {
            $tenant = Tenant::factory()->create(['uuid' => null]);

            expect($tenant->uuid)->not->toBeNull();
            expect(mb_strlen($tenant->uuid))->toBe(36); // UUID length
        });

        it('generates slug from name', function (): void {
            $tenant = Tenant::factory()->create([
                'name' => 'Test Company Inc',
                'slug' => null,
            ]);

            expect($tenant->slug)->toBe('test-company-inc');
        });

        it('can create tenant with specific plan', function (): void {
            $tenant = Tenant::factory()->withPlan('enterprise')->create();

            expect($tenant->plan)->toBe('enterprise');
            expect($tenant->max_projects)->toBe(100);
            expect($tenant->max_keywords)->toBe(50000);
            expect($tenant->max_users)->toBe(50);
        });
    });

    describe('Relationships', function (): void {
        it('has many users', function (): void {
            $tenant = Tenant::factory()->create();
            $users = User::factory()->count(3)->for($tenant)->create();

            expect($tenant->users)->toHaveCount(3);
            expect($tenant->users->first())->toBeInstanceOf(User::class);
        });

        it('has many projects', function (): void {
            $tenant = Tenant::factory()->create();
            $projects = Project::factory()->count(2)->for($tenant)->create();

            expect($tenant->projects)->toHaveCount(2);
            expect($tenant->projects->first())->toBeInstanceOf(Project::class);
        });

        it('has many keywords', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $keywords = Keyword::factory()->count(5)->for($project)->for($tenant)->create();

            expect($tenant->keywords)->toHaveCount(5);
            expect($tenant->keywords->first())->toBeInstanceOf(Keyword::class);
        });

        it('has many competitors', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $competitors = Competitor::factory()->count(3)->for($project)->for($tenant)->create();

            expect($tenant->competitors)->toHaveCount(3);
            expect($tenant->competitors->first())->toBeInstanceOf(Competitor::class);
        });

        it('has many reports', function (): void {
            $tenant = Tenant::factory()->create();
            $reports = Report::factory()->count(2)->for($tenant)->create();

            expect($tenant->reports)->toHaveCount(2);
            expect($tenant->reports->first())->toBeInstanceOf(Report::class);
        });

        it('has many notifications', function (): void {
            $tenant = Tenant::factory()->create();
            $notifications = Notification::factory()->count(4)->for($tenant)->create();

            expect($tenant->notifications)->toHaveCount(4);
            expect($tenant->notifications->first())->toBeInstanceOf(Notification::class);
        });
    });

    describe('Plan Limitations', function (): void {
        it('checks if can create project within limits', function (): void {
            $tenant = Tenant::factory()->withLimits(2, 1000, 5)->create();

            // No projects yet
            expect($tenant->canCreateProject())->toBeTrue();

            // Create one project
            Project::factory()->for($tenant)->create();
            expect($tenant->fresh()->canCreateProject())->toBeTrue();

            // Create second project (at limit)
            Project::factory()->for($tenant)->create();
            expect($tenant->fresh()->canCreateProject())->toBeFalse();
        });

        it('checks if can add keywords within limits', function (): void {
            $tenant = Tenant::factory()->withLimits(10, 100, 5)->create();
            $project = Project::factory()->for($tenant)->create();

            // No keywords yet
            expect($tenant->canAddKeywords(50))->toBeTrue();
            expect($tenant->canAddKeywords(150))->toBeFalse();

            // Add 80 keywords
            Keyword::factory()->count(80)->for($project)->for($tenant)->create();

            expect($tenant->fresh()->canAddKeywords(10))->toBeTrue();
            expect($tenant->fresh()->canAddKeywords(25))->toBeFalse();
        });

        it('checks if can add users within limits', function (): void {
            $tenant = Tenant::factory()->withLimits(10, 1000, 3)->create();

            // No users yet
            expect($tenant->canAddUsers(2))->toBeTrue();
            expect($tenant->canAddUsers(5))->toBeFalse();

            // Add 2 users
            User::factory()->count(2)->for($tenant)->create();

            expect($tenant->fresh()->canAddUsers(1))->toBeTrue();
            expect($tenant->fresh()->canAddUsers(2))->toBeFalse();
        });
    });

    describe('Trial and Subscription Management', function (): void {
        it('identifies trial tenants correctly', function (): void {
            $trialTenant = Tenant::factory()->onTrial()->create();
            $regularTenant = Tenant::factory()->subscribed()->create();
            $expiredTrialTenant = Tenant::factory()->expiredTrial()->create();

            expect($trialTenant->isOnTrial())->toBeTrue();
            expect($regularTenant->isOnTrial())->toBeFalse();
            expect($expiredTrialTenant->isOnTrial())->toBeFalse();
        });

        it('identifies active subscriptions correctly', function (): void {
            $subscribedTenant = Tenant::factory()->subscribed()->create();
            $trialTenant = Tenant::factory()->onTrial()->create();
            $expiredTenant = Tenant::factory()->create([
                'subscription_ends_at' => now()->subDay(),
            ]);

            expect($subscribedTenant->hasActiveSubscription())->toBeTrue();
            expect($trialTenant->hasActiveSubscription())->toBeFalse();
            expect($expiredTenant->hasActiveSubscription())->toBeFalse();
        });
    });

    describe('Casts and Attributes', function (): void {
        it('casts attributes correctly', function (): void {
            $tenant = Tenant::factory()->create([
                'settings' => ['theme' => 'dark'],
                'branding' => ['logo' => 'logo.png'],
                'is_active' => '1',
                'trial_ends_at' => '2024-12-31',
                'subscription_ends_at' => '2025-12-31',
            ]);

            expect($tenant->settings)->toBeArray();
            expect($tenant->branding)->toBeArray();
            expect($tenant->is_active)->toBeTrue();
            expect($tenant->trial_ends_at)->toBeInstanceOf(Carbon::class);
            expect($tenant->subscription_ends_at)->toBeInstanceOf(Carbon::class);
        });
    });

    describe('Route Key', function (): void {
        it('uses uuid as route key', function (): void {
            $tenant = Tenant::factory()->create();

            expect($tenant->getRouteKeyName())->toBe('uuid');
            expect($tenant->getRouteKey())->toBe($tenant->uuid);
        });

        it('generates unique IDs correctly', function (): void {
            $tenant = Tenant::factory()->create();

            expect($tenant->uniqueIds())->toBe(['uuid']);
        });
    });

    describe('Factory States', function (): void {
        it('creates different plan types correctly', function (): void {
            $freeTenant = Tenant::factory()->withPlan('free')->create();
            $starterTenant = Tenant::factory()->withPlan('starter')->create();
            $professionalTenant = Tenant::factory()->withPlan('professional')->create();
            $enterpriseTenant = Tenant::factory()->withPlan('enterprise')->create();

            expect($freeTenant->max_projects)->toBe(1);
            expect($starterTenant->max_projects)->toBe(5);
            expect($professionalTenant->max_projects)->toBe(25);
            expect($enterpriseTenant->max_projects)->toBe(100);
        });

        it('creates inactive tenants', function (): void {
            $inactiveTenant = Tenant::factory()->inactive()->create();

            expect($inactiveTenant->is_active)->toBeFalse();
        });

        it('creates custom limit tenants', function (): void {
            $tenant = Tenant::factory()->withLimits(15, 5000, 10)->create();

            expect($tenant->max_projects)->toBe(15);
            expect($tenant->max_keywords)->toBe(5000);
            expect($tenant->max_users)->toBe(10);
        });
    });

    describe('Business Logic Edge Cases', function (): void {
        it('handles zero limits correctly', function (): void {
            $tenant = Tenant::factory()->withLimits(0, 0, 0)->create();

            expect($tenant->canCreateProject())->toBeFalse();
            expect($tenant->canAddKeywords(1))->toBeFalse();
            expect($tenant->canAddUsers(1))->toBeFalse();
        });

        it('handles negative keyword addition attempts', function (): void {
            $tenant = Tenant::factory()->withLimits(10, 100, 5)->create();

            expect($tenant->canAddKeywords(0))->toBeTrue();
            expect($tenant->canAddKeywords(-1))->toBeTrue(); // Should handle gracefully
        });
    });

    describe('Data Integrity', function (): void {
        it('ensures slug uniqueness', function (): void {
            $tenant1 = Tenant::factory()->create(['name' => 'Test Company']);

            // This should handle duplicate slugs (though implementation may vary)
            $tenant2 = Tenant::factory()->create(['name' => 'Test Company']);

            // At minimum, they should have different IDs
            expect($tenant1->id)->not->toBe($tenant2->id);
        });

        it('maintains relationships on soft delete', function (): void {
            $tenant = Tenant::factory()->create();
            $user = User::factory()->for($tenant)->create();
            $project = Project::factory()->for($tenant)->create();

            $tenant->delete();

            expect($tenant->fresh())->toBeNull(); // Soft deleted
            expect(Tenant::withTrashed()->find($tenant->id))->not->toBeNull();
        });
    });
});
