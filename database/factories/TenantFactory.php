<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        
        return [
            'uuid' => Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'domain' => fake()->domainName(),
            'settings' => [
                'default_country' => fake()->countryCode(),
                'default_language' => fake()->randomElement(['en', 'es', 'fr', 'de']),
                'timezone' => fake()->timezone(),
                'currency' => fake()->currencyCode(),
            ],
            'branding' => [
                'logo_url' => fake()->imageUrl(200, 200, 'business'),
                'primary_color' => fake()->hexColor(),
                'secondary_color' => fake()->hexColor(),
            ],
            'plan' => fake()->randomElement(['free', 'starter', 'professional', 'enterprise']),
            'max_projects' => fake()->numberBetween(5, 100),
            'max_keywords' => fake()->numberBetween(500, 10000),
            'max_users' => fake()->numberBetween(5, 50),
            'is_active' => true,
            'trial_ends_at' => fake()->optional(0.3)->dateTimeBetween('now', '+30 days'),
            'subscription_ends_at' => fake()->optional(0.7)->dateTimeBetween('+30 days', '+1 year'),
        ];
    }

    /**
     * Create a tenant on trial
     */
    public function onTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'free',
            'trial_ends_at' => fake()->dateTimeBetween('+1 day', '+30 days'),
            'subscription_ends_at' => null,
        ]);
    }

    /**
     * Create an expired trial tenant
     */
    public function expiredTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'free',
            'trial_ends_at' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'subscription_ends_at' => null,
        ]);
    }

    /**
     * Create a subscribed tenant
     */
    public function subscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => fake()->randomElement(['starter', 'professional', 'enterprise']),
            'trial_ends_at' => fake()->optional()->dateTimeBetween('-60 days', '-30 days'),
            'subscription_ends_at' => fake()->dateTimeBetween('+30 days', '+1 year'),
        ]);
    }

    /**
     * Create an inactive tenant
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a tenant with specific plan
     */
    public function withPlan(string $plan): static
    {
        $limits = [
            'free' => ['projects' => 1, 'keywords' => 100, 'users' => 2],
            'starter' => ['projects' => 5, 'keywords' => 1000, 'users' => 5],
            'professional' => ['projects' => 25, 'keywords' => 5000, 'users' => 15],
            'enterprise' => ['projects' => 100, 'keywords' => 50000, 'users' => 50],
        ];

        $planLimits = $limits[$plan] ?? $limits['free'];

        return $this->state(fn (array $attributes) => [
            'plan' => $plan,
            'max_projects' => $planLimits['projects'],
            'max_keywords' => $planLimits['keywords'],
            'max_users' => $planLimits['users'],
        ]);
    }

    /**
     * Create a tenant with custom limits
     */
    public function withLimits(int $projects, int $keywords, int $users): static
    {
        return $this->state(fn (array $attributes) => [
            'max_projects' => $projects,
            'max_keywords' => $keywords,
            'max_users' => $users,
        ]);
    }
}