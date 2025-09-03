<?php

namespace Database\Factories;

use App\Models\Keyword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Keyword>
 */
class KeywordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keyword = $this->generateKeyword();
        
        return [
            'keyword' => $keyword,
            'keyword_hash' => md5(strtolower(trim($keyword))),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'categories' => fake()->randomElements([
                'brand', 'product', 'informational', 'commercial', 'navigational'
            ], rand(1, 2)),
            'intent' => fake()->randomElement(['informational', 'navigational', 'commercial', 'transactional']),
            'country' => fake()->randomElement(['US', 'GB', 'CA', 'AU', 'DE', 'FR', 'ES']),
            'language' => fake()->randomElement(['en', 'es', 'fr', 'de']),
            'location' => fake()->optional(0.3)->city(),
            'search_volume' => fake()->optional(0.8)->numberBetween(10, 100000),
            'difficulty_score' => fake()->optional(0.7)->randomFloat(2, 1, 100),
            'cpc' => fake()->optional(0.6)->randomFloat(2, 0.1, 50),
            'competition' => fake()->optional(0.7)->randomFloat(2, 0, 1),
            'related_keywords' => fake()->optional(0.5)->words(rand(3, 8)),
            'current_position' => fake()->optional(0.8)->numberBetween(1, 100),
            'previous_position' => fake()->optional(0.6)->numberBetween(1, 100),
            'position_last_updated' => fake()->optional(0.8)->date(),
            'is_tracking_active' => fake()->boolean(85),
            'tags' => fake()->optional(0.4)->words(rand(1, 3)),
            'notes' => fake()->optional(0.2)->paragraph(),
        ];
    }

    /**
     * Generate realistic keywords
     */
    private function generateKeyword(): string
    {
        $keywordTypes = [
            'product' => [
                'buy', 'purchase', 'shop', 'order', 'price', 'cost', 'cheap', 'best', 'review'
            ],
            'service' => [
                'service', 'company', 'professional', 'expert', 'consultant', 'agency'
            ],
            'informational' => [
                'how to', 'what is', 'why', 'when', 'where', 'guide', 'tutorial', 'tips'
            ],
            'local' => [
                'near me', 'in [city]', 'local', 'nearby'
            ]
        ];

        $industries = [
            'digital marketing', 'seo', 'web design', 'software development', 'consulting',
            'real estate', 'healthcare', 'legal services', 'automotive', 'fitness',
            'restaurant', 'hotel', 'ecommerce', 'saas', 'mobile app'
        ];

        $type = fake()->randomElement(array_keys($keywordTypes));
        $modifier = fake()->randomElement($keywordTypes[$type]);
        $industry = fake()->randomElement($industries);

        // Generate different keyword patterns
        return match ($type) {
            'product' => "{$modifier} {$industry}",
            'service' => "{$industry} {$modifier}",
            'informational' => "{$modifier} {$industry}",
            'local' => "{$industry} {$modifier}",
            default => "{$modifier} {$industry}"
        };
    }

    /**
     * Create a high-priority keyword
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 'high',
            'search_volume' => fake()->numberBetween(1000, 50000),
        ]);
    }

    /**
     * Create a keyword with high search volume
     */
    public function highVolume(): static
    {
        return $this->state(fn (array $attributes) => [
            'search_volume' => fake()->numberBetween(10000, 100000),
        ]);
    }

    /**
     * Create a keyword ranking in top 10
     */
    public function topTen(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_position' => fake()->numberBetween(1, 10),
            'previous_position' => fake()->optional()->numberBetween(1, 20),
            'is_tracking_active' => true,
        ]);
    }

    /**
     * Create a keyword ranking in top 3
     */
    public function topThree(): static
    {
        return $this->state(fn (array $attributes) => [
            'current_position' => fake()->numberBetween(1, 3),
            'previous_position' => fake()->optional()->numberBetween(1, 10),
            'is_tracking_active' => true,
        ]);
    }

    /**
     * Create an improving keyword
     */
    public function improving(): static
    {
        $current = fake()->numberBetween(1, 50);
        $previous = fake()->numberBetween($current + 1, 100);
        
        return $this->state(fn (array $attributes) => [
            'current_position' => $current,
            'previous_position' => $previous,
            'is_tracking_active' => true,
        ]);
    }

    /**
     * Create a declining keyword
     */
    public function declining(): static
    {
        $previous = fake()->numberBetween(1, 50);
        $current = fake()->numberBetween($previous + 1, 100);
        
        return $this->state(fn (array $attributes) => [
            'current_position' => $current,
            'previous_position' => $previous,
            'is_tracking_active' => true,
        ]);
    }

    /**
     * Create an inactive keyword
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_tracking_active' => false,
        ]);
    }

    /**
     * Create a keyword with specific intent
     */
    public function withIntent(string $intent): static
    {
        return $this->state(fn (array $attributes) => [
            'intent' => $intent,
        ]);
    }

    /**
     * Create a branded keyword
     */
    public function branded(): static
    {
        return $this->state(fn (array $attributes) => [
            'categories' => ['brand'],
            'intent' => 'navigational',
            'current_position' => fake()->numberBetween(1, 5), // Brand keywords usually rank well
        ]);
    }
}