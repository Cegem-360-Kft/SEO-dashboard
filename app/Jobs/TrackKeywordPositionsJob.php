<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Keyword;
use App\Services\SerpTrackingService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Exception;

class TrackKeywordPositionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $maxExceptions = 3;
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Project $project,
        private ?Collection $keywords = null,
        private bool $sendAlerts = true
    ) {
        // Set queue based on project priority or size
        $keywordCount = $keywords?->count() ?? $project->keywords()->where('is_active', true)->count();
        
        if ($keywordCount > 100) {
            $this->onQueue('long-running');
        } elseif ($keywordCount > 50) {
            $this->onQueue('medium');
        } else {
            $this->onQueue('default');
        }
    }

    /**
     * Execute the job.
     */
    public function handle(SerpTrackingService $serpService, NotificationService $notificationService): void
    {
        try {
            Log::info("Starting position tracking for project: {$this->project->name}", [
                'project_id' => $this->project->id,
                'tenant_id' => $this->project->tenant_id,
            ]);

            // Get keywords to track
            $keywords = $this->keywords ?? $this->project->keywords()
                ->where('is_active', true)
                ->orderBy('priority', 'desc')
                ->orderBy('search_volume', 'desc')
                ->get();

            if ($keywords->isEmpty()) {
                Log::warning("No active keywords found for project: {$this->project->name}");
                return;
            }

            $tracked = 0;
            $failed = 0;
            $alerts = [];
            $batchSize = 10; // Process in batches to respect rate limits
            
            // Process keywords in batches
            foreach ($keywords->chunk($batchSize) as $batch) {
                foreach ($batch as $keyword) {
                    try {
                        $previousPosition = $keyword->latest_position;
                        $position = $serpService->trackKeywordPosition($keyword);
                        
                        if ($position) {
                            $tracked++;
                            
                            // Check for significant position changes for alerts
                            if ($this->sendAlerts && $previousPosition) {
                                $change = $previousPosition - $position->position;
                                $alert = $this->checkForPositionAlert($keyword, $previousPosition, $position->position, $change);
                                
                                if ($alert) {
                                    $alerts[] = $alert;
                                }
                            }
                        } else {
                            $failed++;
                        }
                        
                    } catch (Exception $e) {
                        $failed++;
                        Log::error("Failed to track position for keyword: {$keyword->term}", [
                            'keyword_id' => $keyword->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                // Rate limiting between batches (SERP APIs often have strict limits)
                if ($keywords->count() > $batchSize) {
                    sleep(2); // 2 second delay between batches
                }
            }

            // Send alerts if any significant changes were detected
            if (!empty($alerts) && $this->sendAlerts) {
                $this->sendPositionAlerts($alerts, $notificationService);
            }

            // Update project's last tracking timestamp
            $this->project->update(['last_tracked_at' => now()]);

            // Log completion
            Log::info("Position tracking completed for project: {$this->project->name}", [
                'project_id' => $this->project->id,
                'total_keywords' => $keywords->count(),
                'tracked' => $tracked,
                'failed' => $failed,
                'alerts_sent' => count($alerts),
            ]);

            // Dispatch follow-up jobs if needed
            if ($tracked > 0) {
                // Calculate project metrics after tracking
                UpdateProjectMetricsJob::dispatch($this->project)->delay(now()->addMinutes(5));
                
                // Generate automatic reports if scheduled
                if ($this->shouldGenerateReport()) {
                    GenerateScheduledReportsJob::dispatch($this->project)->delay(now()->addMinutes(10));
                }
            }

        } catch (Exception $e) {
            Log::error("Position tracking job failed for project: {$this->project->name}", [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Check if position change warrants an alert
     */
    private function checkForPositionAlert(Keyword $keyword, int $previousPosition, int $newPosition, int $change): ?array
    {
        // Define alert thresholds
        $significantChange = 5;
        $majorChange = 10;
        $topPositionChange = 3;

        // Check for significant improvements
        if ($change > $significantChange) {
            $priority = $change > $majorChange ? 'high' : 'medium';
            
            return [
                'keyword' => $keyword,
                'type' => 'improvement',
                'priority' => $priority,
                'previous_position' => $previousPosition,
                'new_position' => $newPosition,
                'change' => $change,
                'title' => "Keyword Improvement: {$keyword->term}",
                'message' => "'{$keyword->term}' improved by {$change} positions (from {$previousPosition} to {$newPosition})",
            ];
        }

        // Check for significant declines
        if ($change < -$significantChange) {
            $priority = $change < -$majorChange ? 'high' : 'medium';
            
            return [
                'keyword' => $keyword,
                'type' => 'decline',
                'priority' => $priority,
                'previous_position' => $previousPosition,
                'new_position' => $newPosition,
                'change' => $change,
                'title' => "Keyword Decline: {$keyword->term}",
                'message' => "'{$keyword->term}' declined by " . abs($change) . " positions (from {$previousPosition} to {$newPosition})",
            ];
        }

        // Check for changes in top positions (more sensitive)
        if (($previousPosition <= 10 || $newPosition <= 10) && abs($change) >= $topPositionChange) {
            $type = $change > 0 ? 'improvement' : 'decline';
            $verb = $change > 0 ? 'improved' : 'declined';
            
            return [
                'keyword' => $keyword,
                'type' => $type,
                'priority' => 'high',
                'previous_position' => $previousPosition,
                'new_position' => $newPosition,
                'change' => $change,
                'title' => "Top 10 Position Change: {$keyword->term}",
                'message' => "'{$keyword->term}' {$verb} by " . abs($change) . " positions in the top 10 (from {$previousPosition} to {$newPosition})",
            ];
        }

        // Check for entering/leaving top 10
        if ($previousPosition > 10 && $newPosition <= 10) {
            return [
                'keyword' => $keyword,
                'type' => 'milestone',
                'priority' => 'high',
                'previous_position' => $previousPosition,
                'new_position' => $newPosition,
                'change' => $change,
                'title' => "Entered Top 10: {$keyword->term}",
                'message' => "'{$keyword->term}' entered the top 10 at position {$newPosition}",
            ];
        }

        if ($previousPosition <= 10 && $newPosition > 10) {
            return [
                'keyword' => $keyword,
                'type' => 'milestone',
                'priority' => 'high',
                'previous_position' => $previousPosition,
                'new_position' => $newPosition,
                'change' => $change,
                'title' => "Left Top 10: {$keyword->term}",
                'message' => "'{$keyword->term}' dropped out of the top 10 to position {$newPosition}",
            ];
        }

        return null;
    }

    /**
     * Send position change alerts
     */
    private function sendPositionAlerts(array $alerts, NotificationService $notificationService): void
    {
        try {
            // Group alerts by type and priority
            $groupedAlerts = collect($alerts)->groupBy(function ($alert) {
                return $alert['type'] . '_' . $alert['priority'];
            });

            foreach ($groupedAlerts as $group => $alertGroup) {
                // For multiple alerts of the same type, create a summary
                if ($alertGroup->count() > 1) {
                    $this->sendBatchAlert($alertGroup, $notificationService);
                } else {
                    $this->sendSingleAlert($alertGroup->first(), $notificationService);
                }
            }

        } catch (Exception $e) {
            Log::error("Failed to send position alerts", [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a single position alert
     */
    private function sendSingleAlert(array $alert, NotificationService $notificationService): void
    {
        $notificationService->sendPositionAlert($this->project, $alert);
    }

    /**
     * Send a batch alert for multiple position changes
     */
    private function sendBatchAlert(Collection $alerts, NotificationService $notificationService): void
    {
        $first = $alerts->first();
        $count = $alerts->count();
        
        $alertData = [
            'type' => 'batch_position_change',
            'priority' => $first['priority'],
            'title' => ucfirst($first['type']) . " Alert: {$count} keywords",
            'message' => "{$count} keywords experienced " . $first['type'] . " in rankings",
            'keywords' => $alerts->pluck('keyword.term')->toArray(),
            'changes' => $alerts->map(function ($alert) {
                return [
                    'keyword' => $alert['keyword']->term,
                    'change' => $alert['change'],
                    'previous_position' => $alert['previous_position'],
                    'new_position' => $alert['new_position'],
                ];
            })->toArray(),
        ];

        $notificationService->sendPositionAlert($this->project, $alertData);
    }

    /**
     * Check if we should generate a report after tracking
     */
    private function shouldGenerateReport(): bool
    {
        // Check if project has scheduled reports for today
        return $this->project->reports()
            ->where('is_automated', true)
            ->where('status', 'scheduled')
            ->where('schedule->frequency', 'daily')
            ->exists();
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Position tracking job permanently failed", [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Notify project administrators of the failure
        try {
            $notificationService = app(NotificationService::class);
            $notificationService->sendPositionAlert($this->project, [
                'type' => 'system_error',
                'priority' => 'high',
                'title' => 'Position Tracking Failed',
                'message' => "Automated position tracking failed for {$this->project->name}. Please check your configuration.",
                'error' => $exception->getMessage(),
            ]);
        } catch (Exception $e) {
            Log::error("Failed to send failure notification", [
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
        return [30, 120, 300]; // 30 seconds, 2 minutes, 5 minutes
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30); // Give up after 30 minutes
    }
}