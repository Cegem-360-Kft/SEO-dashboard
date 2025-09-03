<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Competitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competitor>
 */
final class CompetitorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyName = fake()->company();
        $domain = fake()->domainName();

        return [
            'name' => $companyName,
            'domain' => $domain,
            'url' => 'https://'.$domain,
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'categories' => fake()->randomElements([
                'direct_competitor', 'indirect_competitor', 'aspiration', 'industry_leader',
            ], rand(1, 2)),
            'estimated_traffic' => fake()->numberBetween(1000, 10000000),
            'domain_authority' => fake()->numberBetween(20, 95),
            'backlinks_count' => fake()->numberBetween(1000, 1000000),
            'estimated_value' => fake()->randomFloat(2, 1000, 500000),
            'top_keywords' => $this->generateTopKeywords(),
            'shared_keywords_count' => [
                'total' => $sharedTotal = fake()->numberBetween(50, 5000),
                'top_10' => fake()->numberBetween(5, min(100, $sharedTotal)),
                'top_50' => fake()->numberBetween(20, min(500, $sharedTotal)),
                'improving' => fake()->numberBetween(0, min(50, $sharedTotal)),
                'declining' => fake()->numberBetween(0, min(50, $sharedTotal)),
            ],
            'visibility_score' => fake()->randomFloat(4, 0, 100),
            'is_active' => true,
            'last_analyzed_at' => fake()->optional(0.8)->dateTimeBetween('-7 days'),
        ];
    }

    /**
     * Create a high-priority competitor
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => 'high',
            'domain_authority' => fake()->numberBetween(70, 95),
            'estimated_traffic' => fake()->numberBetween(100000, 10000000),
        ]);
    }

    /**
     * Create a strong competitor
     */
    public function strong(): static
    {
        return $this->state(fn (array $attributes): array => [
            'domain_authority' => fake()->numberBetween(80, 95),
            'estimated_traffic' => fake()->numberBetween(500000, 10000000),
            'backlinks_count' => fake()->numberBetween(100000, 1000000),
            'visibility_score' => fake()->randomFloat(4, 80, 100),
        ]);
    }

    /**
     * Create a weak competitor
     */
    public function weak(): static
    {
        return $this->state(fn (array $attributes): array => [
            'domain_authority' => fake()->numberBetween(20, 40),
            'estimated_traffic' => fake()->numberBetween(1000, 10000),
            'backlinks_count' => fake()->numberBetween(1000, 10000),
            'visibility_score' => fake()->randomFloat(4, 0, 30),
        ]);
    }

    /**
     * Create an inactive competitor
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a competitor that needs analysis
     */
    public function needsAnalysis(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_analyzed_at' => fake()->optional(0.5)->dateTimeBetween('-30 days', '-8 days'),
        ]);
    }

    /**
     * Create a recently analyzed competitor
     */
    public function recentlyAnalyzed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_analyzed_at' => fake()->dateTimeBetween('-3 days'),
        ]);
    }

    /**
     * Create a direct competitor
     */
    public function direct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'categories' => ['direct_competitor'],
            'priority' => fake()->randomElement(['medium', 'high', 'critical']),
        ]);
    }

    /**
     * Generate realistic top keywords for competitor
     */
    private function generateTopKeywords(): array
    {
        $keywords = [];
        $count = fake()->numberBetween(10, 50);

        $industries = [
            'digital marketing', 'seo services', 'web design', 'software development',
            'consulting', 'marketing agency', 'web development', 'ecommerce',
        ];

        for ($i = 0; $i < $count; $i++) {
            $keywords[] = [
                'keyword' => fake()->randomElement($industries).' '.fake()->words(rand(1, 3), true),
                'position' => fake()->numberBetween(1, 100),
                'search_volume' => fake()->numberBetween(100, 50000),
                'traffic' => fake()->numberBetween(10, 5000),
                'value' => fake()->randomFloat(2, 10, 1000),
            ];
        }

        return $keywords;
    }
}
