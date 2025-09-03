<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Report;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
final class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['positions', 'keywords', 'competitors', 'overview', 'custom']);
        $frequency = fake()->randomElement(['manual', 'daily', 'weekly', 'monthly', 'quarterly']);

        return [
            'name' => $this->generateReportName($type),
            'description' => fake()->paragraph(),
            'type' => $type,
            'frequency' => $frequency,
            'config' => $this->generateConfig($type),
            'recipients' => fake()->optional(0.7)->randomElements([
                fake()->safeEmail(),
                fake()->safeEmail(),
                fake()->safeEmail(),
            ], rand(1, 3)),
            'format' => fake()->randomElement(['pdf', 'excel', 'csv', 'json']),
            'is_active' => fake()->boolean(80),
            'is_automated' => $frequency !== 'manual',
            'period_start' => fake()->dateTimeBetween('-30 days', '-1 day'),
            'period_end' => fake()->dateTimeBetween('-1 day'),
            'last_generated_at' => fake()->optional(0.6)->dateTimeBetween('-7 days'),
            'next_generation_at' => $frequency !== 'manual' ? $this->calculateNextGeneration($frequency) : null,
            'file_path' => fake()->optional(0.5)->filePath(),
            'file_size' => fake()->optional(0.5)->numberBetween(1024, 10485760), // 1KB to 10MB
            'status' => fake()->randomElement(['pending', 'generating', 'completed', 'failed']),
            'error_message' => fake()->optional(0.1)->sentence(),
        ];
    }

    /**
     * Create a completed report
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'last_generated_at' => fake()->dateTimeBetween('-7 days'),
            'file_path' => '/reports/'.fake()->uuid().'.pdf',
            'file_size' => fake()->numberBetween(512000, 5242880), // 512KB to 5MB
            'error_message' => null,
        ]);
    }

    /**
     * Create a failed report
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'failed',
            'error_message' => fake()->randomElement([
                'Failed to generate PDF',
                'Database connection timeout',
                'Insufficient data for report period',
                'Template rendering error',
                'Export limit exceeded',
            ]),
            'file_path' => null,
            'file_size' => null,
        ]);
    }

    /**
     * Create an automated report
     */
    public function automated(): static
    {
        $frequency = fake()->randomElement(['daily', 'weekly', 'monthly']);

        return $this->state(fn (array $attributes): array => [
            'is_automated' => true,
            'frequency' => $frequency,
            'next_generation_at' => $this->calculateNextGeneration($frequency),
        ]);
    }

    /**
     * Create a manual report
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_automated' => false,
            'frequency' => 'manual',
            'next_generation_at' => null,
        ]);
    }

    /**
     * Create a report that's due for generation
     */
    public function dueForGeneration(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_automated' => true,
            'is_active' => true,
            'frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'next_generation_at' => fake()->dateTimeBetween('-2 days', 'now'),
        ]);
    }

    /**
     * Create an inactive report
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a report with specific type
     */
    public function withType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'name' => $this->generateReportName($type),
            'config' => $this->generateConfig($type),
        ]);
    }

    /**
     * Generate report name based on type
     */
    private function generateReportName(string $type): string
    {
        return match ($type) {
            'positions' => 'Position Tracking Report',
            'keywords' => 'Keyword Performance Report',
            'competitors' => 'Competitor Analysis Report',
            'overview' => 'SEO Overview Report',
            'custom' => 'Custom SEO Report',
            default => 'SEO Report'
        }.' - '.fake()->date('Y-m-d');
    }

    /**
     * Generate config based on report type
     */
    private function generateConfig(string $type): array
    {
        $baseConfig = [
            'include_charts' => fake()->boolean(80),
            'include_summary' => fake()->boolean(90),
            'date_range' => fake()->randomElement(['7d', '30d', '90d', '1y']),
            'branding' => fake()->boolean(60),
        ];

        return match ($type) {
            'positions' => array_merge($baseConfig, [
                'position_threshold' => fake()->numberBetween(10, 50),
                'include_serp_features' => fake()->boolean(70),
                'group_by_device' => fake()->boolean(50),
                'include_competitors' => fake()->boolean(60),
            ]),
            'keywords' => array_merge($baseConfig, [
                'min_search_volume' => fake()->numberBetween(0, 1000),
                'include_trending' => fake()->boolean(80),
                'include_opportunities' => fake()->boolean(70),
                'keyword_categories' => fake()->randomElements(['brand', 'product', 'commercial'], rand(1, 3)),
            ]),
            'competitors' => array_merge($baseConfig, [
                'include_shared_keywords' => fake()->boolean(90),
                'include_gap_analysis' => fake()->boolean(80),
                'competitor_limit' => fake()->numberBetween(3, 10),
                'metrics' => ['visibility', 'traffic', 'keywords', 'backlinks'],
            ]),
            'overview' => array_merge($baseConfig, [
                'include_all_projects' => fake()->boolean(70),
                'include_alerts' => fake()->boolean(80),
                'kpi_focus' => fake()->randomElements(['traffic', 'positions', 'visibility'], rand(2, 3)),
            ]),
            default => $baseConfig
        };
    }

    /**
     * Calculate next generation time based on frequency
     */
    private function calculateNextGeneration(string $frequency): ?Carbon
    {
        return match ($frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            default => null,
        };
    }
}
