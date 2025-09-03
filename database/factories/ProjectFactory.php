<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'name' => fake()->company().' SEO Project',
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'description' => fake()->paragraph(),
            'target_countries' => fake()->randomElements(['US', 'GB', 'CA', 'AU', 'DE', 'FR', 'ES'], rand(1, 3)),
            'target_languages' => fake()->randomElements(['en', 'es', 'fr', 'de'], rand(1, 2)),
            'search_engines' => ['google', 'bing'],
            'devices' => ['desktop', 'mobile'],
            'integrations' => [
                'google_search_console' => fake()->boolean(60),
                'google_analytics' => fake()->boolean(50),
                'google_ads' => fake()->boolean(30),
            ],
            'settings' => [
                'tracking_frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
                'alert_threshold' => fake()->numberBetween(5, 20),
                'competitor_tracking' => fake()->boolean(),
                'serp_features_tracking' => fake()->boolean(80),
            ],
            'is_active' => true,
            'last_crawled_at' => fake()->optional(0.8)->dateTimeBetween('-7 days'),
            'last_positions_updated_at' => fake()->optional(0.9)->dateTimeBetween('-3 days'),
        ];
    }

    /**
     * Create an inactive project
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a project that needs position update
     */
    public function needsUpdate(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_positions_updated_at' => fake()->dateTimeBetween('-7 days', '-2 days'),
        ]);
    }

    /**
     * Create a project with specific domain
     */
    public function withDomain(string $domain): static
    {
        return $this->state(fn (array $attributes): array => [
            'url' => 'https://'.$domain,
            'domain' => $domain,
        ]);
    }

    /**
     * Create a project for specific countries
     */
    public function forCountries(array $countries): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_countries' => $countries,
        ]);
    }

    /**
     * Create a project with integrations enabled
     */
    public function withIntegrations(): static
    {
        return $this->state(fn (array $attributes): array => [
            'integrations' => [
                'google_search_console' => true,
                'google_analytics' => true,
                'google_ads' => true,
            ],
        ]);
    }

    /**
     * Create a recently updated project
     */
    public function recentlyUpdated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_crawled_at' => fake()->dateTimeBetween('-1 day'),
            'last_positions_updated_at' => fake()->dateTimeBetween('-1 day'),
        ]);
    }
}
