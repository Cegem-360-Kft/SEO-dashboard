<?php

namespace Tests;

use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure database is clean
        $this->artisan('migrate:fresh');

        // Mock external services in testing
        $this->mockExternalServices();

        // Set testing tenant context
        $this->setupTenantContext();
    }

    protected function tearDown(): void
    {
        $this->clearTenantContext();

        parent::tearDown();
    }

    /**
     * Mock all external services for testing
     */
    protected function mockExternalServices(): void
    {
        config([
            'services.serp_api.mock' => true,
            'services.google_api.mock' => true,
            'services.analytics_api.mock' => true,
        ]);
    }

    /**
     * Setup tenant context for multi-tenant testing
     */
    protected function setupTenantContext(): void
    {
        // This will be implemented based on your tenant resolution strategy
    }

    /**
     * Clear tenant context after test
     */
    protected function clearTenantContext(): void
    {
        // Clear any tenant-specific contexts
    }

    /**
     * Create and authenticate as a tenant user
     */
    protected function actingAsTenantUser(array $userAttributes = [], array $tenantAttributes = []): User
    {
        $tenant = Tenant::factory()->create($tenantAttributes);
        $user = User::factory()->for($tenant)->create($userAttributes);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Create a tenant with users and return both
     */
    protected function createTenantWithUsers(int $userCount = 1, array $tenantAttributes = [], array $userAttributes = []): array
    {
        $tenant = Tenant::factory()->create($tenantAttributes);
        $users = User::factory()->count($userCount)->for($tenant)->create($userAttributes);

        return [$tenant, $users];
    }

    /**
     * Assert that a user can only see data from their tenant
     */
    protected function assertTenantIsolation(User $user, string $model, array $tenantData, array $otherTenantData): void
    {
        $this->actingAs($user);

        $response = $this->getJson('/api/'.$model);

        $response->assertStatus(200);

        // Should see tenant data
        foreach ($tenantData as $data) {
            $response->assertJsonFragment($data);
        }

        // Should NOT see other tenant data
        foreach ($otherTenantData as $data) {
            $response->assertJsonMissing($data);
        }
    }

    /**
     * Performance testing helper
     */
    protected function assertQueryCountLessThan(int $maxQueries, callable $callback): void
    {
        $queryCount = 0;

        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $callback();

        $this->assertLessThan($maxQueries, $queryCount, sprintf('Expected less than %d queries, but %d were executed.', $maxQueries, $queryCount));
    }

    /**
     * Assert response time is acceptable
     */
    protected function assertResponseTimeLessThan(int $maxMilliseconds, callable $callback): void
    {
        $start = microtime(true);
        $callback();
        $end = microtime(true);

        $duration = ($end - $start) * 1000; // Convert to milliseconds

        $this->assertLessThan($maxMilliseconds, $duration, sprintf('Response took %sms, expected less than %dms', $duration, $maxMilliseconds));
    }

    /**
     * Create sample SEO data for testing
     */
    protected function createSampleSeoData(Tenant $tenant): array
    {
        $project = Project::factory()->for($tenant)->create();
        $keywords = Keyword::factory()->count(10)->for($project)->for($tenant)->create();
        $positions = [];

        foreach ($keywords as $keyword) {
            $positions[] = KeywordPosition::factory()
                ->for($keyword)
                ->for($tenant)
                ->create();
        }

        return ['project' => $project, 'keywords' => $keywords, 'positions' => $positions];
    }
}
