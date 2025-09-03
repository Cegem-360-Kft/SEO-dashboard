<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KeywordPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeywordPosition>
 */
final class KeywordPositionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $position = fake()->numberBetween(1, 100);
        $searchVolume = fake()->optional(0.8)->numberBetween(100, 10000) ?? 1000;
        $cpc = fake()->optional(0.7)->randomFloat(2, 0.1, 10) ?? 1.0;

        return [
            'date' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'search_engine' => fake()->randomElement(['google', 'bing', 'yahoo']),
            'device' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'position' => $position,
            'url' => fake()->url(),
            'serp_features' => fake()->optional(0.3)->randomElements([
                'featured_snippet', 'people_also_ask', 'image_pack', 'video',
                'local_pack', 'shopping', 'ads',
            ], rand(1, 3)),
            'estimated_traffic' => $this->calculateEstimatedTraffic($position, $searchVolume),
            'estimated_value' => $this->calculateEstimatedValue($position, $searchVolume, $cpc),
            'is_featured_snippet' => fake()->boolean(5), // 5% chance
            'is_local_pack' => fake()->boolean(8), // 8% chance
            'is_paid_above' => fake()->boolean(15), // 15% chance of ads above
            'ads_count' => fake()->numberBetween(0, 4),
            'serp_title' => fake()->optional(0.9)->sentence(),
            'serp_description' => fake()->optional(0.8)->paragraph(),
            'checked_at' => fake()->dateTimeBetween('-1 day'),
        ];
    }

    /**
     * Create a position in top 3
     */
    public function topThree(): static
    {
        return $this->state(fn (array $attributes): array => [
            'position' => fake()->numberBetween(1, 3),
        ]);
    }

    /**
     * Create a position in top 10
     */
    public function topTen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'position' => fake()->numberBetween(1, 10),
        ]);
    }

    /**
     * Create a position with featured snippet
     */
    public function withFeaturedSnippet(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_featured_snippet' => true,
            'serp_features' => ['featured_snippet'],
            'position' => fake()->numberBetween(1, 5), // Featured snippets usually in top 5
        ]);
    }

    /**
     * Create a position with local pack
     */
    public function withLocalPack(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_local_pack' => true,
            'serp_features' => ['local_pack'],
        ]);
    }

    /**
     * Create a position for specific date
     */
    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => $date,
        ]);
    }

    /**
     * Create a position for specific search engine
     */
    public function forSearchEngine(string $engine): static
    {
        return $this->state(fn (array $attributes): array => [
            'search_engine' => $engine,
        ]);
    }

    /**
     * Create a position for specific device
     */
    public function forDevice(string $device): static
    {
        return $this->state(fn (array $attributes): array => [
            'device' => $device,
        ]);
    }

    /**
     * Create a position with high estimated value
     */
    public function highValue(): static
    {
        return $this->state(function (array $attributes): array {
            $position = fake()->numberBetween(1, 10);
            $searchVolume = fake()->numberBetween(5000, 50000);
            $cpc = fake()->randomFloat(2, 5, 25);

            return [
                'position' => $position,
                'estimated_traffic' => $this->calculateEstimatedTraffic($position, $searchVolume),
                'estimated_value' => $this->calculateEstimatedValue($position, $searchVolume, $cpc),
            ];
        });
    }

    /**
     * Create recent position data
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => fake()->dateTimeBetween('-3 days')->format('Y-m-d'),
            'checked_at' => fake()->dateTimeBetween('-3 days'),
        ]);
    }

    /**
     * Calculate estimated traffic based on position and search volume
     */
    private function calculateEstimatedTraffic(int $position, int $searchVolume): int
    {
        // CTR curve based on position
        $ctrCurve = [
            1 => 0.3149, 2 => 0.1555, 3 => 0.1006, 4 => 0.0697, 5 => 0.0513,
            6 => 0.0403, 7 => 0.0329, 8 => 0.0276, 9 => 0.0238, 10 => 0.0208,
            11 => 0.0186, 12 => 0.0169, 13 => 0.0154, 14 => 0.0142, 15 => 0.0131,
            16 => 0.0122, 17 => 0.0114, 18 => 0.0107, 19 => 0.0101, 20 => 0.0095,
        ];

        $ctr = $ctrCurve[$position] ?? 0.01;

        return (int) ($searchVolume * $ctr);
    }

    /**
     * Calculate estimated value based on position, search volume, and CPC
     */
    private function calculateEstimatedValue(int $position, int $searchVolume, float $cpc): float
    {
        $traffic = $this->calculateEstimatedTraffic($position, $searchVolume);

        return round($traffic * $cpc, 2);
    }
}
