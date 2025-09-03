<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement([
            'position_drop', 'position_gain', 'new_top_10', 'lost_top_10',
            'featured_snippet_gained', 'featured_snippet_lost', 'competitor_activity',
            'technical_issue', 'report_ready', 'crawl_error', 'system_alert',
        ]);

        $severity = fake()->randomElement(['low', 'medium', 'high', 'critical']);

        return [
            'type' => $type,
            'severity' => $severity,
            'title' => $this->generateTitle($type, $severity),
            'message' => $this->generateMessage($type),
            'data' => $this->generateData($type),
            'channel' => fake()->randomElement(['in_app', 'email', 'push', 'webhook']),
            'is_read' => fake()->boolean(30), // 30% are read
            'is_sent' => fake()->boolean(80), // 80% are sent
            'sent_at' => fake()->optional(0.8)->dateTimeBetween('-7 days'),
            'read_at' => fake()->optional(0.3)->dateTimeBetween('-7 days'),
            'delivery_status' => fake()->optional(0.8)->randomElements([
                ['email' => 'delivered', 'timestamp' => now()->toISOString()],
                ['push' => 'sent', 'timestamp' => now()->toISOString()],
                ['webhook' => 'success', 'timestamp' => now()->toISOString()],
            ], 1)[0] ?? [],
        ];
    }

    /**
     * Create an unread notification
     */
    public function unread(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    /**
     * Create a read notification
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_read' => true,
            'read_at' => fake()->dateTimeBetween('-7 days'),
        ]);
    }

    /**
     * Create a critical notification
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => 'critical',
            'type' => fake()->randomElement(['position_drop', 'technical_issue', 'crawl_error']),
            'channel' => fake()->randomElement(['email', 'push', 'in_app']),
        ]);
    }

    /**
     * Create a notification with specific type
     */
    public function withType(string $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'title' => $this->generateTitle($type, $attributes['severity'] ?? 'medium'),
            'message' => $this->generateMessage($type),
            'data' => $this->generateData($type),
        ]);
    }

    /**
     * Create a notification with specific severity
     */
    public function withSeverity(string $severity): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => $severity,
            'title' => $this->generateTitle($attributes['type'] ?? 'system_alert', $severity),
        ]);
    }

    /**
     * Create an unsent notification
     */
    public function unsent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_sent' => false,
            'sent_at' => null,
            'delivery_status' => [],
        ]);
    }

    /**
     * Create a sent notification
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_sent' => true,
            'sent_at' => fake()->dateTimeBetween('-7 days'),
            'delivery_status' => [
                'email' => 'delivered',
                'timestamp' => fake()->dateTimeBetween('-7 days')->toISOString(),
            ],
        ]);
    }

    /**
     * Generate notification title based on type and severity
     */
    private function generateTitle(string $type, string $severity): string
    {
        $severityPrefix = $severity === 'critical' ? '🚨 CRITICAL: ' :
                         ($severity === 'high' ? '⚠️ ' : '');

        return $severityPrefix.match ($type) {
            'position_drop' => 'Keyword Position Dropped',
            'position_gain' => 'Keyword Position Improved',
            'new_top_10' => 'New Top 10 Ranking!',
            'lost_top_10' => 'Lost Top 10 Position',
            'featured_snippet_gained' => 'Featured Snippet Gained!',
            'featured_snippet_lost' => 'Featured Snippet Lost',
            'competitor_activity' => 'Competitor Movement Detected',
            'technical_issue' => 'Technical Issue Detected',
            'report_ready' => 'Report Ready for Download',
            'crawl_error' => 'Crawl Error Occurred',
            'system_alert' => 'System Alert',
            default => 'SEO Notification'
        };
    }

    /**
     * Generate notification message based on type
     */
    private function generateMessage(string $type): string
    {
        return match ($type) {
            'position_drop' => 'Your keyword "'.fake()->words(3, true).'" dropped from position '.
                              fake()->numberBetween(1, 20).' to '.fake()->numberBetween(21, 50),
            'position_gain' => 'Your keyword "'.fake()->words(3, true).'" improved from position '.
                              fake()->numberBetween(21, 50).' to '.fake()->numberBetween(1, 20),
            'new_top_10' => 'Congratulations! "'.fake()->words(3, true).'" is now ranking in the top 10 at position '.
                           fake()->numberBetween(1, 10),
            'lost_top_10' => 'Your keyword "'.fake()->words(3, true).'" dropped out of the top 10 to position '.
                            fake()->numberBetween(11, 30),
            'featured_snippet_gained' => 'Great news! Your page now appears in the featured snippet for "'.
                                        fake()->words(3, true).'"',
            'featured_snippet_lost' => 'Your featured snippet for "'.fake()->words(3, true).'" was taken by a competitor',
            'competitor_activity' => 'Competitor '.fake()->company().' is gaining ground with '.
                                    fake()->numberBetween(5, 50).' new top 20 rankings',
            'technical_issue' => fake()->randomElement([
                'High 4xx error rate detected on your website',
                'Page speed has significantly decreased',
                'Multiple pages are not indexable',
                'SSL certificate issues detected',
            ]),
            'report_ready' => 'Your '.fake()->randomElement(['weekly', 'monthly', 'quarterly']).
                             ' SEO report is ready for download',
            'crawl_error' => 'Unable to crawl '.fake()->numberBetween(10, 100).' pages on your website',
            'system_alert' => fake()->randomElement([
                'API rate limit approaching',
                'Data sync completed',
                'New features available',
                'Scheduled maintenance notification',
            ]),
            default => fake()->sentence()
        };
    }

    /**
     * Generate notification data based on type
     */
    private function generateData(string $type): array
    {
        return match ($type) {
            'position_drop', 'position_gain' => [
                'keyword' => fake()->words(3, true),
                'previous_position' => fake()->numberBetween(1, 50),
                'current_position' => fake()->numberBetween(1, 50),
                'search_engine' => 'google',
                'device' => fake()->randomElement(['desktop', 'mobile']),
                'change' => fake()->numberBetween(-30, 30),
            ],
            'new_top_10', 'lost_top_10' => [
                'keyword' => fake()->words(3, true),
                'position' => fake()->numberBetween(1, 30),
                'search_volume' => fake()->numberBetween(100, 50000),
                'estimated_traffic' => fake()->numberBetween(10, 1000),
            ],
            'featured_snippet_gained', 'featured_snippet_lost' => [
                'keyword' => fake()->words(3, true),
                'url' => fake()->url(),
                'snippet_type' => fake()->randomElement(['paragraph', 'list', 'table']),
            ],
            'competitor_activity' => [
                'competitor' => fake()->company(),
                'domain' => fake()->domainName(),
                'new_rankings' => fake()->numberBetween(5, 50),
                'shared_keywords_affected' => fake()->numberBetween(3, 20),
            ],
            'technical_issue' => [
                'issue_type' => fake()->randomElement(['4xx_errors', 'page_speed', 'indexability', 'ssl']),
                'affected_pages' => fake()->numberBetween(1, 100),
                'severity_score' => fake()->randomFloat(2, 1, 10),
            ],
            'report_ready' => [
                'report_type' => fake()->randomElement(['positions', 'keywords', 'competitors', 'overview']),
                'period' => fake()->dateRange('-30 days', 'now')->format('Y-m-d'),
                'file_size' => fake()->numberBetween(512, 5120).'KB',
                'download_url' => '/reports/'.fake()->uuid().'.pdf',
            ],
            default => []
        };
    }
}
