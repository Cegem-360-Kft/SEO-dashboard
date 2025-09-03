<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\AnalyticsService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class CollectAnalyticsDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240; // 4 minutes
    public int $maxExceptions = 3;
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Project $project,
        private string $dataSource = 'both', // 'gsc', 'ga4', or 'both'
        private ?string $startDate = null,
        private ?string $endDate = null
    ) {
        $this->onQueue('analytics');
        
        // Set default date range to yesterday
        $this->startDate = $startDate ?? now()->subDay()->format('Y-m-d');
        $this->endDate = $endDate ?? now()->subDay()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(AnalyticsService $analyticsService, NotificationService $notificationService): void
    {
        try {
            Log::info("Starting analytics data collection", [
                'project_id' => $this->project->id,
                'project_name' => $this->project->name,
                'data_source' => $this->dataSource,
                'date_range' => "{$this->startDate} to {$this->endDate}",
            ]);

            $collectedData = [];
            $errors = [];

            // Collect Google Search Console data
            if (in_array($this->dataSource, ['gsc', 'both']) && $this->project->gsc_property_url) {
                try {
                    $gscData = $this->collectSearchConsoleData($analyticsService);
                    $collectedData['search_console'] = $gscData;
                    
                    Log::info("Search Console data collected successfully", [
                        'project_id' => $this->project->id,
                        'queries_count' => count($gscData['queries'] ?? []),
                        'total_clicks' => $gscData['summary']['total_clicks'] ?? 0,
                    ]);
                } catch (Exception $e) {
                    $errors['search_console'] = $e->getMessage();
                    Log::error("Failed to collect Search Console data", [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Collect Google Analytics 4 data
            if (in_array($this->dataSource, ['ga4', 'both']) && $this->project->ga4_property_id) {
                try {
                    $ga4Data = $this->collectAnalyticsData($analyticsService);
                    $collectedData['google_analytics'] = $ga4Data;
                    
                    Log::info("Google Analytics data collected successfully", [
                        'project_id' => $this->project->id,
                        'sessions' => $ga4Data['overview']['sessions'] ?? 0,
                        'users' => $ga4Data['overview']['users'] ?? 0,
                    ]);
                } catch (Exception $e) {
                    $errors['google_analytics'] = $e->getMessage();
                    Log::error("Failed to collect Google Analytics data", [
                        'project_id' => $this->project->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Store collected data in cache and database
            if (!empty($collectedData)) {
                $this->storeAnalyticsData($collectedData);
            }

            // Check for significant changes and send alerts
            if (!empty($collectedData)) {
                $this->checkForTrafficAlerts($collectedData, $notificationService);
            }

            // Update project's last analytics sync timestamp
            $this->project->update(['last_analytics_sync_at' => now()]);

            // Log summary
            Log::info("Analytics data collection completed", [
                'project_id' => $this->project->id,
                'collected_sources' => array_keys($collectedData),
                'errors' => array_keys($errors),
                'date_range' => "{$this->startDate} to {$this->endDate}",
            ]);

            // If there were partial failures, we don't want to fail the entire job
            if (!empty($errors) && empty($collectedData)) {
                throw new Exception("Failed to collect any analytics data: " . implode(', ', $errors));
            }

        } catch (Exception $e) {
            Log::error("Analytics data collection job failed", [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Collect Google Search Console data
     */
    private function collectSearchConsoleData(AnalyticsService $analyticsService): array
    {
        // Check if we need to get an access token
        $accessToken = $this->getStoredAccessToken('gsc');
        if ($accessToken) {
            $analyticsService->setAccessToken($accessToken);
        }

        return $analyticsService->getSearchConsoleData($this->project, [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);
    }

    /**
     * Collect Google Analytics 4 data
     */
    private function collectAnalyticsData(AnalyticsService $analyticsService): array
    {
        // Check if we need to get an access token
        $accessToken = $this->getStoredAccessToken('ga4');
        if ($accessToken) {
            $analyticsService->setAccessToken($accessToken);
        }

        return $analyticsService->getAnalyticsData($this->project, [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);
    }

    /**
     * Get stored access token for the service
     */
    private function getStoredAccessToken(string $service): ?string
    {
        // In a real implementation, you'd retrieve stored OAuth tokens
        // This could be from the tenant settings, user settings, or a separate tokens table
        $settingsKey = $service === 'gsc' ? 'google_search_console_token' : 'google_analytics_token';
        
        return $this->project->tenant->settings[$settingsKey] ?? null;
    }

    /**
     * Store analytics data in cache and database
     */
    private function storeAnalyticsData(array $collectedData): void
    {
        try {
            // Store in cache for quick access (30 days retention)
            $cacheKey = "analytics_data:{$this->project->id}:{$this->startDate}:{$this->endDate}";
            Cache::put($cacheKey, $collectedData, now()->addDays(30));

            // Store aggregated data in database for historical tracking
            $this->storeHistoricalData($collectedData);

            Log::info("Analytics data stored successfully", [
                'project_id' => $this->project->id,
                'cache_key' => $cacheKey,
            ]);

        } catch (Exception $e) {
            Log::error("Failed to store analytics data", [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store historical analytics data in database
     */
    private function storeHistoricalData(array $data): void
    {
        // Create or update analytics snapshot record
        // This would typically go to a dedicated analytics_snapshots table
        
        $snapshot = [
            'project_id' => $this->project->id,
            'date' => $this->startDate,
            'data_source' => $this->dataSource,
            'data' => $data,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // In a real implementation, you'd have an AnalyticsSnapshot model
        // AnalyticsSnapshot::updateOrCreate([...], $snapshot);
    }

    /**
     * Check for significant traffic changes and send alerts
     */
    private function checkForTrafficAlerts(array $currentData, NotificationService $notificationService): void
    {
        try {
            // Get previous period data for comparison
            $previousPeriod = $this->getPreviousPeriodData();
            
            if (!$previousPeriod) {
                Log::info("No previous period data available for comparison");
                return;
            }

            $alerts = $this->analyzeTrafficChanges($currentData, $previousPeriod);

            foreach ($alerts as $alert) {
                $notificationService->sendTrafficAlert($this->project, $alert);
            }

            if (!empty($alerts)) {
                Log::info("Traffic alerts sent", [
                    'project_id' => $this->project->id,
                    'alerts_count' => count($alerts),
                ]);
            }

        } catch (Exception $e) {
            Log::error("Failed to check for traffic alerts", [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get data from the previous period for comparison
     */
    private function getPreviousPeriodData(): ?array
    {
        $daysDiff = \Carbon\Carbon::parse($this->startDate)->diffInDays(\Carbon\Carbon::parse($this->endDate)) + 1;
        
        $prevEndDate = \Carbon\Carbon::parse($this->startDate)->subDay()->format('Y-m-d');
        $prevStartDate = \Carbon\Carbon::parse($prevEndDate)->subDays($daysDiff - 1)->format('Y-m-d');
        
        $cacheKey = "analytics_data:{$this->project->id}:{$prevStartDate}:{$prevEndDate}";
        
        return Cache::get($cacheKey);
    }

    /**
     * Analyze traffic changes and return alerts
     */
    private function analyzeTrafficChanges(array $currentData, array $previousData): array
    {
        $alerts = [];

        // Analyze Search Console traffic
        if (isset($currentData['search_console']) && isset($previousData['search_console'])) {
            $current = $currentData['search_console']['summary'] ?? [];
            $previous = $previousData['search_console']['summary'] ?? [];
            
            if ($current['total_clicks'] > 0 && $previous['total_clicks'] > 0) {
                $changePercent = (($current['total_clicks'] - $previous['total_clicks']) / $previous['total_clicks']) * 100;
                
                if (abs($changePercent) >= 25) { // 25% change threshold
                    $alerts[] = [
                        'type' => 'search_console_traffic',
                        'current_traffic' => $current['total_clicks'],
                        'previous_traffic' => $previous['total_clicks'],
                        'percentage_change' => round($changePercent, 2),
                        'metric' => 'organic_clicks',
                        'period' => "{$this->startDate} to {$this->endDate}",
                    ];
                }
            }
        }

        // Analyze Google Analytics traffic
        if (isset($currentData['google_analytics']) && isset($previousData['google_analytics'])) {
            $current = $currentData['google_analytics']['overview'] ?? [];
            $previous = $previousData['google_analytics']['overview'] ?? [];
            
            if ($current['sessions'] > 0 && $previous['sessions'] > 0) {
                $changePercent = (($current['sessions'] - $previous['sessions']) / $previous['sessions']) * 100;
                
                if (abs($changePercent) >= 20) { // 20% change threshold
                    $alerts[] = [
                        'type' => 'google_analytics_traffic',
                        'current_traffic' => $current['sessions'],
                        'previous_traffic' => $previous['sessions'],
                        'percentage_change' => round($changePercent, 2),
                        'metric' => 'sessions',
                        'period' => "{$this->startDate} to {$this->endDate}",
                    ];
                }
            }
        }

        return $alerts;
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Analytics data collection job permanently failed", [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'data_source' => $this->dataSource,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Send failure notification
        try {
            $notificationService = app(NotificationService::class);
            $notificationService->sendTrafficAlert($this->project, [
                'type' => 'analytics_collection_failed',
                'error' => $exception->getMessage(),
                'data_source' => $this->dataSource,
                'date_range' => "{$this->startDate} to {$this->endDate}",
            ]);
        } catch (Exception $e) {
            Log::error("Failed to send analytics failure notification", [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [60, 180, 300]; // 1 minute, 3 minutes, 5 minutes
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'analytics-collection',
            'project:' . $this->project->id,
            'tenant:' . $this->project->tenant_id,
            'source:' . $this->dataSource,
        ];
    }
}