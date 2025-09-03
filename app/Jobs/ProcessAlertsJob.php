<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\Tenant;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180; // 3 minutes
    public int $maxExceptions = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private ?Tenant $tenant = null,
        private string $alertType = 'all',
        private string $priority = 'all'
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            Log::info("Starting alert processing", [
                'tenant_id' => $this->tenant?->id,
                'alert_type' => $this->alertType,
                'priority' => $this->priority,
            ]);

            // Process tenant-specific alerts or all tenants
            if ($this->tenant) {
                $this->processTenantAlerts($this->tenant, $notificationService);
            } else {
                $this->processAllTenantAlerts($notificationService);
            }

            Log::info("Alert processing completed successfully");

        } catch (Exception $e) {
            Log::error("Alert processing failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Process alerts for all tenants
     */
    private function processAllTenantAlerts(NotificationService $notificationService): void
    {
        $tenants = Tenant::where('status', 'active')->get();
        
        foreach ($tenants as $tenant) {
            try {
                $this->processTenantAlerts($tenant, $notificationService);
            } catch (Exception $e) {
                Log::error("Failed to process alerts for tenant", [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other tenants
            }
        }
    }

    /**
     * Process alerts for a specific tenant
     */
    private function processTenantAlerts(Tenant $tenant, NotificationService $notificationService): void
    {
        // Get pending notifications for the tenant
        $notifications = $this->getPendingNotifications($tenant);

        if ($notifications->isEmpty()) {
            Log::debug("No pending notifications for tenant", ['tenant_id' => $tenant->id]);
            return;
        }

        Log::info("Processing notifications for tenant", [
            'tenant_id' => $tenant->id,
            'notification_count' => $notifications->count(),
        ]);

        // Group notifications by type and priority for batching
        $grouped = $notifications->groupBy(function ($notification) {
            return $notification->type . '_' . $notification->priority;
        });

        foreach ($grouped as $group => $groupNotifications) {
            $this->processNotificationGroup($groupNotifications, $notificationService);
        }

        // Send daily digest if it's time
        if ($this->shouldSendDailyDigest($tenant)) {
            $notificationService->sendDailyDigest($tenant);
        }

        // Send weekly summary if it's time
        if ($this->shouldSendWeeklySummary($tenant)) {
            $notificationService->sendWeeklyPerformanceSummary($tenant);
        }
    }

    /**
     * Get pending notifications for a tenant
     */
    private function getPendingNotifications(Tenant $tenant): Collection
    {
        $query = $tenant->notifications()
            ->where('sent_at', '<=', now())
            ->where('is_processed', false);

        // Filter by alert type
        if ($this->alertType !== 'all') {
            $query->where('type', $this->alertType);
        }

        // Filter by priority
        if ($this->priority !== 'all') {
            $query->where('priority', $this->priority);
        }

        // Process high priority first, then by creation time
        return $query->orderByRaw("
            CASE priority 
                WHEN 'high' THEN 1 
                WHEN 'medium' THEN 2 
                WHEN 'low' THEN 3 
                ELSE 4 
            END, created_at ASC
        ")->limit(100)->get(); // Process in batches of 100
    }

    /**
     * Process a group of notifications
     */
    private function processNotificationGroup(Collection $notifications, NotificationService $notificationService): void
    {
        $type = $notifications->first()->type;
        $priority = $notifications->first()->priority;

        Log::debug("Processing notification group", [
            'type' => $type,
            'priority' => $priority,
            'count' => $notifications->count(),
        ]);

        foreach ($notifications as $notification) {
            try {
                $this->processIndividualNotification($notification, $notificationService);
                
                // Mark as processed
                $notification->update(['is_processed' => true, 'processed_at' => now()]);
                
            } catch (Exception $e) {
                Log::error("Failed to process individual notification", [
                    'notification_id' => $notification->id,
                    'type' => $notification->type,
                    'error' => $e->getMessage(),
                ]);

                // Mark as failed
                $notification->update([
                    'is_processed' => true,
                    'processed_at' => now(),
                    'processing_error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process an individual notification
     */
    private function processIndividualNotification(Notification $notification, NotificationService $notificationService): void
    {
        // Get users who should receive this notification
        $users = $this->getNotificationRecipients($notification);

        if ($users->isEmpty()) {
            Log::debug("No recipients found for notification", [
                'notification_id' => $notification->id,
                'type' => $notification->type,
            ]);
            return;
        }

        // Send notification based on type
        match($notification->type) {
            'position_alert' => $this->processPositionAlert($notification, $users, $notificationService),
            'traffic_alert' => $this->processTrafficAlert($notification, $users, $notificationService),
            'report_completed' => $this->processReportAlert($notification, $users, $notificationService),
            'ranking_milestone' => $this->processMilestoneAlert($notification, $users, $notificationService),
            'competitor_movement' => $this->processCompetitorAlert($notification, $users, $notificationService),
            'system_alert' => $this->processSystemAlert($notification, $users, $notificationService),
            default => $this->processGenericAlert($notification, $users, $notificationService),
        };
    }

    /**
     * Get recipients for a notification
     */
    private function getNotificationRecipients(Notification $notification): Collection
    {
        $tenant = $notification->tenant;
        $project = $notification->project;

        // Get users based on notification type and user preferences
        $users = $tenant->users()->where('status', 'active');

        // Filter by notification preferences
        $preferenceKey = $this->getPreferenceKey($notification->type);
        if ($preferenceKey) {
            $users->where("notification_preferences->{$preferenceKey}", true);
        }

        // Filter by project access if applicable
        if ($project) {
            $users->whereHas('projects', function($query) use ($project) {
                $query->where('projects.id', $project->id);
            });
        }

        // Filter by priority preferences
        if ($notification->priority === 'low') {
            $users->where('notification_preferences->receive_low_priority', true);
        }

        return $users->get();
    }

    /**
     * Get notification preference key for a type
     */
    private function getPreferenceKey(string $type): ?string
    {
        return match($type) {
            'position_alert' => 'position_alerts',
            'traffic_alert' => 'traffic_alerts',
            'report_completed' => 'report_notifications',
            'ranking_milestone' => 'milestone_notifications',
            'competitor_movement' => 'competitor_alerts',
            'system_alert' => 'system_notifications',
            default => null,
        };
    }

    /**
     * Process position alert
     */
    private function processPositionAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // Position alerts are handled by the main notification service
        // This method can add any additional processing logic
        Log::debug("Processing position alert", ['notification_id' => $notification->id]);
    }

    /**
     * Process traffic alert
     */
    private function processTrafficAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // Traffic alerts are handled by the main notification service
        Log::debug("Processing traffic alert", ['notification_id' => $notification->id]);
    }

    /**
     * Process report completion alert
     */
    private function processReportAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // Report completion notifications
        Log::debug("Processing report alert", ['notification_id' => $notification->id]);
    }

    /**
     * Process milestone alert
     */
    private function processMilestoneAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // Milestone notifications (keywords entering top 10, etc.)
        Log::debug("Processing milestone alert", ['notification_id' => $notification->id]);
    }

    /**
     * Process competitor alert
     */
    private function processCompetitorAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // Competitor movement notifications
        Log::debug("Processing competitor alert", ['notification_id' => $notification->id]);
    }

    /**
     * Process system alert
     */
    private function processSystemAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // System alerts (errors, warnings, etc.)
        Log::debug("Processing system alert", ['notification_id' => $notification->id]);
    }

    /**
     * Process generic alert
     */
    private function processGenericAlert(Notification $notification, Collection $users, NotificationService $notificationService): void
    {
        // Generic notification processing
        Log::debug("Processing generic alert", [
            'notification_id' => $notification->id,
            'type' => $notification->type,
        ]);
    }

    /**
     * Check if daily digest should be sent
     */
    private function shouldSendDailyDigest(Tenant $tenant): bool
    {
        // Check if it's the right time for daily digest (e.g., 8 AM in tenant's timezone)
        $tenantTimezone = $tenant->settings['timezone'] ?? 'UTC';
        $now = now()->setTimezone($tenantTimezone);
        
        // Send digest at 8 AM
        if ($now->hour !== 8) {
            return false;
        }

        // Check if digest was already sent today
        $lastDigest = $tenant->notifications()
            ->where('type', 'daily_digest')
            ->whereDate('sent_at', $now->toDateString())
            ->exists();

        return !$lastDigest;
    }

    /**
     * Check if weekly summary should be sent
     */
    private function shouldSendWeeklySummary(Tenant $tenant): bool
    {
        $tenantTimezone = $tenant->settings['timezone'] ?? 'UTC';
        $now = now()->setTimezone($tenantTimezone);
        
        // Send weekly summary on Mondays at 9 AM
        if ($now->dayOfWeek !== 1 || $now->hour !== 9) {
            return false;
        }

        // Check if summary was already sent this week
        $weekStart = $now->startOfWeek();
        $lastSummary = $tenant->notifications()
            ->where('type', 'weekly_summary')
            ->where('sent_at', '>=', $weekStart)
            ->exists();

        return !$lastSummary;
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Alert processing job permanently failed", [
            'tenant_id' => $this->tenant?->id,
            'alert_type' => $this->alertType,
            'priority' => $this->priority,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Create a system notification about the failure
        if ($this->tenant) {
            try {
                Notification::create([
                    'tenant_id' => $this->tenant->id,
                    'type' => 'system_alert',
                    'title' => 'Alert Processing Failed',
                    'message' => 'Failed to process notifications: ' . $exception->getMessage(),
                    'priority' => 'high',
                    'data' => [
                        'error' => $exception->getMessage(),
                        'job_details' => [
                            'alert_type' => $this->alertType,
                            'priority' => $this->priority,
                        ],
                    ],
                    'sent_at' => now(),
                    'is_processed' => false,
                ]);
            } catch (Exception $e) {
                Log::error("Failed to create failure notification", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 120]; // 30 seconds, 2 minutes
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        $tags = ['alert-processing', 'notifications'];
        
        if ($this->tenant) {
            $tags[] = 'tenant:' . $this->tenant->id;
        }
        
        if ($this->alertType !== 'all') {
            $tags[] = 'type:' . $this->alertType;
        }
        
        if ($this->priority !== 'all') {
            $tags[] = 'priority:' . $this->priority;
        }
        
        return $tags;
    }
}