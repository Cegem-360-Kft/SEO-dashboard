<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $events = [
            'user.login', 'user.logout', 'user.created', 'user.updated', 'user.deleted',
            'project.created', 'project.updated', 'project.deleted', 'project.accessed',
            'keyword.created', 'keyword.updated', 'keyword.deleted', 'keyword.imported',
            'report.generated', 'report.downloaded', 'report.shared',
            'settings.updated', 'api.accessed', 'data.exported',
            'security.unauthorized_access', 'security.login_failed', 'security.password_changed'
        ];
        
        $event = fake()->randomElement($events);
        
        return [
            'event' => $event,
            'auditable_type' => $this->generateAuditableType($event),
            'auditable_id' => fake()->numberBetween(1, 1000),
            'old_values' => $this->generateOldValues($event),
            'new_values' => $this->generateNewValues($event),
            'url' => fake()->url(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'tags' => $this->generateTags($event),
        ];
    }

    /**
     * Generate auditable type based on event
     */
    private function generateAuditableType(string $event): ?string
    {
        $eventParts = explode('.', $event);
        $resource = $eventParts[0] ?? null;
        
        return match ($resource) {
            'user' => 'App\\Models\\User',
            'project' => 'App\\Models\\Project',
            'keyword' => 'App\\Models\\Keyword',
            'report' => 'App\\Models\\Report',
            'tenant' => 'App\\Models\\Tenant',
            default => null
        };
    }

    /**
     * Generate old values based on event type
     */
    private function generateOldValues(string $event): ?array
    {
        if (str_contains($event, '.updated') || str_contains($event, '.deleted')) {
            return match (true) {
                str_contains($event, 'user.') => [
                    'name' => fake()->name(),
                    'email' => fake()->email(),
                    'role' => fake()->randomElement(['viewer', 'editor', 'manager']),
                    'is_active' => fake()->boolean(),
                ],
                str_contains($event, 'project.') => [
                    'name' => fake()->company() . ' Website',
                    'url' => fake()->url(),
                    'is_active' => fake()->boolean(),
                    'settings' => ['tracking_frequency' => 'weekly'],
                ],
                str_contains($event, 'keyword.') => [
                    'keyword' => fake()->words(3, true),
                    'priority' => 'medium',
                    'is_tracking_active' => true,
                    'current_position' => fake()->numberBetween(1, 100),
                ],
                str_contains($event, 'settings.') => [
                    'notifications_enabled' => true,
                    'email_reports' => false,
                    'api_access' => true,
                ],
                default => []
            };
        }
        
        return null;
    }

    /**
     * Generate new values based on event type
     */
    private function generateNewValues(string $event): array
    {
        return match (true) {
            str_contains($event, 'login') => [
                'login_at' => now()->toISOString(),
                'session_id' => fake()->uuid(),
            ],
            str_contains($event, 'logout') => [
                'logout_at' => now()->toISOString(),
                'session_duration' => fake()->numberBetween(300, 7200), // 5 mins to 2 hours
            ],
            str_contains($event, 'user.created') => [
                'name' => fake()->name(),
                'email' => fake()->email(),
                'role' => fake()->randomElement(['viewer', 'editor', 'manager']),
                'is_active' => true,
            ],
            str_contains($event, 'user.updated') => [
                'name' => fake()->name(),
                'email' => fake()->email(),
                'role' => fake()->randomElement(['viewer', 'editor', 'manager', 'admin']),
                'is_active' => fake()->boolean(),
                'updated_fields' => fake()->randomElements(['name', 'email', 'role', 'permissions'], 2),
            ],
            str_contains($event, 'project.created') => [
                'name' => fake()->company() . ' SEO Project',
                'url' => fake()->url(),
                'target_countries' => fake()->randomElements(['US', 'GB', 'CA'], 2),
                'is_active' => true,
            ],
            str_contains($event, 'keyword.imported') => [
                'imported_count' => fake()->numberBetween(10, 500),
                'file_name' => fake()->word() . '_keywords.csv',
                'file_size' => fake()->numberBetween(1024, 102400),
            ],
            str_contains($event, 'report.generated') => [
                'report_type' => fake()->randomElement(['positions', 'keywords', 'overview']),
                'period_start' => fake()->date(),
                'period_end' => fake()->date(),
                'file_size' => fake()->numberBetween(512000, 5242880),
            ],
            str_contains($event, 'api.accessed') => [
                'endpoint' => fake()->randomElement(['/api/projects', '/api/keywords', '/api/reports']),
                'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
                'response_code' => fake()->randomElement([200, 201, 400, 401, 403, 404]),
                'response_time' => fake()->numberBetween(50, 2000),
            ],
            str_contains($event, 'security.') => [
                'threat_level' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
                'blocked' => fake()->boolean(70),
                'details' => fake()->sentence(),
            ],
            str_contains($event, 'unauthorized_access') => [
                'attempted_resource' => fake()->randomElement(['/admin', '/api/users', '/settings']),
                'user_permissions' => fake()->randomElements(['view_projects', 'edit_keywords'], 1),
                'required_permissions' => fake()->randomElements(['admin_access', 'manage_users'], 1),
            ],
            str_contains($event, 'login_failed') => [
                'reason' => fake()->randomElement(['invalid_credentials', 'account_locked', 'too_many_attempts']),
                'attempts_count' => fake()->numberBetween(1, 5),
            ],
            default => [
                'timestamp' => now()->toISOString(),
                'action' => $event,
            ]
        };
    }

    /**
     * Generate tags based on event type
     */
    private function generateTags(string $event): array
    {
        $tags = [];
        
        if (str_contains($event, 'security.') || str_contains($event, 'unauthorized') || str_contains($event, 'failed')) {
            $tags[] = 'security';
        }
        
        if (str_contains($event, 'login') || str_contains($event, 'logout')) {
            $tags[] = 'authentication';
        }
        
        if (str_contains($event, 'api.')) {
            $tags[] = 'api';
        }
        
        if (str_contains($event, '.created') || str_contains($event, '.updated') || str_contains($event, '.deleted')) {
            $tags[] = 'data_modification';
        }
        
        if (str_contains($event, '.accessed') || str_contains($event, '.downloaded')) {
            $tags[] = 'data_access';
        }
        
        // Add resource-specific tags
        $eventParts = explode('.', $event);
        if (!empty($eventParts[0])) {
            $tags[] = $eventParts[0];
        }
        
        return array_unique($tags);
    }

    /**
     * Create a security event
     */
    public function securityEvent(): static
    {
        $securityEvents = [
            'security.unauthorized_access', 'security.login_failed', 
            'security.password_changed', 'security.suspicious_activity'
        ];
        
        return $this->state(fn (array $attributes) => [
            'event' => fake()->randomElement($securityEvents),
            'tags' => ['security', 'alert'],
        ]);
    }

    /**
     * Create an authentication event
     */
    public function authEvent(): static
    {
        $authEvents = ['user.login', 'user.logout', 'user.password_changed'];
        
        return $this->state(fn (array $attributes) => [
            'event' => fake()->randomElement($authEvents),
            'tags' => ['authentication'],
        ]);
    }

    /**
     * Create a data modification event
     */
    public function dataModification(): static
    {
        $dataEvents = [
            'project.created', 'project.updated', 'project.deleted',
            'keyword.created', 'keyword.updated', 'keyword.deleted',
            'user.created', 'user.updated', 'user.deleted'
        ];
        
        return $this->state(fn (array $attributes) => [
            'event' => fake()->randomElement($dataEvents),
            'tags' => ['data_modification'],
        ]);
    }

    /**
     * Create an API access event
     */
    public function apiAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'event' => 'api.accessed',
            'tags' => ['api', 'access'],
            'new_values' => [
                'endpoint' => fake()->randomElement(['/api/projects', '/api/keywords', '/api/reports']),
                'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
                'response_code' => fake()->randomElement([200, 201, 400, 401, 403]),
                'response_time' => fake()->numberBetween(50, 1000),
            ],
        ]);
    }

    /**
     * Create an event for specific model
     */
    public function forModel(string $modelType, int $modelId): static
    {
        return $this->state(fn (array $attributes) => [
            'auditable_type' => $modelType,
            'auditable_id' => $modelId,
        ]);
    }

    /**
     * Create a failed login attempt
     */
    public function failedLogin(): static
    {
        return $this->state(fn (array $attributes) => [
            'event' => 'security.login_failed',
            'tags' => ['security', 'authentication', 'failed_login'],
            'new_values' => [
                'email' => fake()->email(),
                'reason' => fake()->randomElement(['invalid_credentials', 'account_locked']),
                'attempts_count' => fake()->numberBetween(1, 5),
            ],
        ]);
    }
}