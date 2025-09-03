<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Integration', 'Performance');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBeWithinRange', function (int $min, int $max) {
    return $this->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
});

expect()->extend('toHaveValidPosition', function () {
    return $this->toBeWithinRange(1, 100);
});

expect()->extend('toBeValidEmail', function () {
    return $this->toMatch('/^[^\s@]+@[^\s@]+\.[^\s@]+$/');
});

expect()->extend('toBeValidUrl', function () {
    return $this->toMatch('/^https?:\/\/.+/');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->create($attributes);
}

function createUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function actingAsTenantUser(?User $user = null, ?Tenant $tenant = null): User
{
    $tenant = $tenant ?? createTenant();
    $user = $user ?? User::factory()->for($tenant)->create();

    test()->actingAs($user);

    return $user;
}

function createAuthenticatedUser(array $userAttributes = [], array $tenantAttributes = []): array
{
    $tenant = createTenant($tenantAttributes);
    $user = User::factory()->for($tenant)->create($userAttributes);

    test()->actingAs($user);

    return [$user, $tenant];
}

function mockExternalApis(): void
{
    config(['services.serp_api.mock' => true]);
    config(['services.google_api.mock' => true]);
    config(['services.analytics_api.mock' => true]);
}
