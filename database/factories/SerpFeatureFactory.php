<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SerpFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerpFeature>
 */
final class SerpFeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $featureType = fake()->randomElement([
            'featured_snippet', 'people_also_ask', 'image_pack', 'video',
            'local_pack', 'shopping', 'news', 'top_stories', 'knowledge_graph',
            'reviews', 'recipes', 'jobs', 'events',
        ]);

        $domain = fake()->domainName();

        return [
            'date' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'search_engine' => fake()->randomElement(['google', 'bing']),
            'device' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'feature_type' => $featureType,
            'position' => fake()->numberBetween(1, 10),
            'domain' => $domain,
            'title' => $this->generateTitle($featureType),
            'description' => fake()->paragraph(),
            'url' => sprintf('https://%s/', $domain).fake()->slug(),
            'data' => $this->generateFeatureData($featureType),
            'is_our_domain' => fake()->boolean(25), // 25% chance it's our domain
        ];
    }

    /**
     * Create a featured snippet
     */
    public function featuredSnippet(): static
    {
        return $this->state(fn (array $attributes): array => [
            'feature_type' => 'featured_snippet',
            'position' => fake()->numberBetween(1, 3),
            'title' => $this->generateTitle('featured_snippet'),
            'data' => $this->generateFeatureData('featured_snippet'),
        ]);
    }

    /**
     * Create a local pack feature
     */
    public function localPack(): static
    {
        return $this->state(fn (array $attributes): array => [
            'feature_type' => 'local_pack',
            'position' => fake()->numberBetween(1, 5),
            'title' => $this->generateTitle('local_pack'),
            'data' => $this->generateFeatureData('local_pack'),
        ]);
    }

    /**
     * Create a feature owned by our domain
     */
    public function ownedByUs(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_our_domain' => true,
        ]);
    }

    /**
     * Create a feature for specific date
     */
    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'date' => $date,
        ]);
    }

    /**
     * Create a feature for specific type
     */
    public function withFeatureType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'feature_type' => $type,
            'title' => $this->generateTitle($type),
            'data' => $this->generateFeatureData($type),
        ]);
    }

    /**
     * Create a mobile feature
     */
    public function mobile(): static
    {
        return $this->state(fn (array $attributes): array => [
            'device' => 'mobile',
        ]);
    }

    /**
     * Create a desktop feature
     */
    public function desktop(): static
    {
        return $this->state(fn (array $attributes): array => [
            'device' => 'desktop',
        ]);
    }

    /**
     * Generate realistic title based on feature type
     */
    private function generateTitle(string $featureType): string
    {
        return match ($featureType) {
            'featured_snippet' => 'What is '.fake()->words(3, true).'?',
            'people_also_ask' => 'How to '.fake()->words(4, true).'?',
            'image_pack' => fake()->words(2, true).' Images',
            'video' => fake()->words(3, true).' - Complete Guide',
            'local_pack' => fake()->company().' - '.fake()->city(),
            'shopping' => fake()->words(2, true).' - $'.fake()->randomFloat(2, 10, 500),
            'news' => fake()->sentence(),
            'top_stories' => fake()->sentence(),
            'knowledge_graph' => fake()->company().' Overview',
            'reviews' => fake()->company().' Reviews',
            'recipes' => 'Best '.fake()->words(2, true).' Recipe',
            'jobs' => fake()->jobTitle().' at '.fake()->company(),
            'events' => fake()->words(3, true).' Event',
            default => fake()->sentence()
        };
    }

    /**
     * Generate feature-specific data
     */
    private function generateFeatureData(string $featureType): array
    {
        return match ($featureType) {
            'featured_snippet' => [
                'snippet_type' => fake()->randomElement(['paragraph', 'list', 'table']),
                'content' => fake()->paragraph(),
                'source_page' => fake()->url(),
            ],
            'people_also_ask' => [
                'questions' => fake()->sentences(4),
                'expanded' => fake()->boolean(),
            ],
            'image_pack' => [
                'image_count' => fake()->numberBetween(3, 12),
                'sources' => fake()->randomElements([
                    fake()->domainName(),
                    fake()->domainName(),
                    fake()->domainName(),
                ], 3),
            ],
            'video' => [
                'duration' => fake()->time('i:s'),
                'thumbnail' => fake()->imageUrl(320, 180),
                'platform' => fake()->randomElement(['youtube', 'vimeo', 'facebook']),
            ],
            'local_pack' => [
                'business_count' => 3,
                'map_shown' => fake()->boolean(80),
                'reviews_shown' => fake()->boolean(90),
                'ratings' => [
                    fake()->randomFloat(1, 3.5, 5.0),
                    fake()->randomFloat(1, 3.5, 5.0),
                    fake()->randomFloat(1, 3.5, 5.0),
                ],
            ],
            'shopping' => [
                'product_count' => fake()->numberBetween(4, 8),
                'price_range' => [
                    'min' => fake()->randomFloat(2, 10, 100),
                    'max' => fake()->randomFloat(2, 100, 500),
                ],
                'merchants' => fake()->randomElements([
                    'Amazon', 'eBay', 'Walmart', 'Target', 'Best Buy',
                ], 3),
            ],
            'news' => [
                'article_count' => fake()->numberBetween(3, 6),
                'publishers' => fake()->randomElements([
                    'CNN', 'BBC', 'Reuters', 'Associated Press', 'The Guardian',
                ], 3),
                'publish_times' => [
                    fake()->dateTimeBetween('-24 hours')->format('Y-m-d H:i:s'),
                    fake()->dateTimeBetween('-24 hours')->format('Y-m-d H:i:s'),
                    fake()->dateTimeBetween('-24 hours')->format('Y-m-d H:i:s'),
                ],
            ],
            'knowledge_graph' => [
                'entity_type' => fake()->randomElement(['organization', 'person', 'place', 'thing']),
                'description' => fake()->paragraph(),
                'facts' => [
                    'Founded' => fake()->year(),
                    'Headquarters' => fake()->city(),
                    'Industry' => fake()->word(),
                ],
            ],
            'reviews' => [
                'rating' => fake()->randomFloat(1, 3.0, 5.0),
                'review_count' => fake()->numberBetween(10, 10000),
                'platforms' => fake()->randomElements([
                    'Google', 'Yelp', 'TripAdvisor', 'Trustpilot',
                ], 2),
            ],
            default => []
        };
    }
}
