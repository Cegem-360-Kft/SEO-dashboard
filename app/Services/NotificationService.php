<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\DailyDigest;
use App\Mail\SeoAlert;
use App\Mail\WeeklyPerformanceSummary;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class NotificationService
{
    /**
     * Create and send position alert notification
     */
    public function sendPositionAlert(Project $project, array $alertData): void
    {
        try {
            $notification = $this->createNotification([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'type' => 'position_alert',
                'title' => $alertData['title'],
                'message' => $alertData['message'],
                'data' => $alertData,
                'priority' => $alertData['priority'] ?? 'medium',
            ]);

            // Send to relevant users
            $users = $this->getProjectUsers($project, ['keywords.alerts']);
            $this->sendToUsers($users, $notification, $alertData);

        } catch (Exception $exception) {
            Log::error('Failed to send position alert', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
                'alert_data' => $alertData,
            ]);
        }
    }

    /**
     * Send traffic change alert
     */
    public function sendTrafficAlert(Project $project, array $trafficData): void
    {
        try {
            $change = $trafficData['percentage_change'] ?? 0;
            $priority = abs($change) > 50 ? 'high' : (abs($change) > 25 ? 'medium' : 'low');

            $notification = $this->createNotification([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'type' => 'traffic_alert',
                'title' => 'Traffic Change Alert: '.$project->name,
                'message' => $this->formatTrafficMessage($trafficData),
                'data' => $trafficData,
                'priority' => $priority,
            ]);

            $users = $this->getProjectUsers($project, ['traffic.alerts']);
            $this->sendToUsers($users, $notification, $trafficData);

        } catch (Exception $exception) {
            Log::error('Failed to send traffic alert', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send report completion notification
     */
    public function sendReportNotification(Project $project, $report): void
    {
        try {
            $notification = $this->createNotification([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'type' => 'report_completed',
                'title' => 'Report Ready: '.$report->title,
                'message' => sprintf('Your SEO report for %s has been generated and is ready for download.', $project->name),
                'data' => [
                    'report_id' => $report->id,
                    'report_type' => $report->type,
                    'download_url' => route('api.reports.download', $report->id),
                ],
                'priority' => 'low',
            ]);

            $users = $this->getProjectUsers($project, ['reports.notifications']);
            $this->sendToUsers($users, $notification, ['report' => $report]);

        } catch (Exception $exception) {
            Log::error('Failed to send report notification', [
                'project_id' => $project->id,
                'report_id' => $report->id ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send keyword ranking milestone notifications
     */
    public function sendRankingMilestone(Project $project, array $milestoneData): void
    {
        try {
            $notification = $this->createNotification([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'type' => 'ranking_milestone',
                'title' => $milestoneData['title'],
                'message' => $milestoneData['message'],
                'data' => $milestoneData,
                'priority' => 'medium',
            ]);

            $users = $this->getProjectUsers($project, ['keywords.milestones']);
            $this->sendToUsers($users, $notification, $milestoneData);

        } catch (Exception $exception) {
            Log::error('Failed to send ranking milestone', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send competitor movement alerts
     */
    public function sendCompetitorAlert(Project $project, array $competitorData): void
    {
        try {
            $notification = $this->createNotification([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'type' => 'competitor_movement',
                'title' => 'Competitor Movement: '.$competitorData['competitor_domain'],
                'message' => $this->formatCompetitorMessage($competitorData),
                'data' => $competitorData,
                'priority' => 'medium',
            ]);

            $users = $this->getProjectUsers($project, ['competitors.alerts']);
            $this->sendToUsers($users, $notification, $competitorData);

        } catch (Exception $exception) {
            Log::error('Failed to send competitor alert', [
                'project_id' => $project->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Mark notifications as read for a user
     */
    public function markAsRead(User $user, array $notificationIds): int
    {
        return $user->notifications()
            ->whereIn('id', $notificationIds)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Get unread notifications for a user
     */
    public function getUnreadNotifications(User $user, int $limit = 50): Collection
    {
        return $user->notifications()
            ->whereNull('read_at')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Send daily digest to users
     */
    public function sendDailyDigest(Tenant $tenant): void
    {
        $users = $tenant->users()
            ->where('notification_preferences->daily_digest', true)
            ->get();

        foreach ($users as $user) {
            try {
                $digestData = $this->prepareDailyDigest($user);

                if (! empty($digestData['projects'])) {
                    Mail::to($user->email)->send(new DailyDigest($digestData));

                    Log::info('Daily digest sent', ['user_id' => $user->id]);
                }

            } catch (Exception $e) {
                Log::error('Failed to send daily digest', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send weekly performance summary
     */
    public function sendWeeklyPerformanceSummary(Tenant $tenant): void
    {
        $users = $tenant->users()
            ->where('notification_preferences->weekly_summary', true)
            ->get();

        foreach ($users as $user) {
            try {
                $summaryData = $this->prepareWeeklySummary($tenant);

                if (! empty($summaryData['projects'])) {
                    Mail::to($user->email)->send(new WeeklyPerformanceSummary($summaryData));

                    Log::info('Weekly summary sent', ['user_id' => $user->id]);
                }

            } catch (Exception $e) {
                Log::error('Failed to send weekly summary', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get notification statistics for a tenant
     */
    public function getNotificationStats(Tenant $tenant, int $days = 30): array
    {
        $notifications = $tenant->notifications()
            ->where('sent_at', '>=', now()->subDays($days))
            ->get();

        return [
            'total_sent' => $notifications->count(),
            'by_type' => $notifications->groupBy('type')->map->count(),
            'by_priority' => $notifications->groupBy('priority')->map->count(),
            'read_rate' => $notifications->isNotEmpty()
                ? round(($notifications->where('is_read', true)->count() / $notifications->count()) * 100, 2)
                : 0,
        ];
    }

    /**
     * Configure notification preferences for a user
     */
    public function updateNotificationPreferences(User $user, array $preferences): void
    {
        $user->update([
            'notification_preferences' => array_merge(
                $user->notification_preferences ?? [],
                $preferences
            ),
        ]);

        Log::info('Notification preferences updated', [
            'user_id' => $user->id,
            'preferences' => $preferences,
        ]);
    }

    /**
     * Create notification record
     */
    private function createNotification(array $data): Notification
    {
        return Notification::query()->create([
            'tenant_id' => $data['tenant_id'],
            'project_id' => $data['project_id'] ?? null,
            'type' => $data['type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'data' => $data['data'] ?? [],
            'priority' => $data['priority'] ?? 'medium',
            'is_read' => false,
            'sent_at' => now(),
        ]);
    }

    /**
     * Get users who should receive project notifications
     */
    private function getProjectUsers(Project $project, array $permissions = []): Collection
    {
        $query = $project->tenant->users();

        // Filter by permissions if specified
        if ($permissions !== []) {
            $query->whereHas('permissions', function ($q) use ($permissions): void {
                $q->whereIn('name', $permissions);
            })->orWhereHas('roles.permissions', function ($q) use ($permissions): void {
                $q->whereIn('name', $permissions);
            });
        }

        return $query->where('notification_preferences->email_alerts', true)->get();
    }

    /**
     * Send notifications to users via multiple channels
     */
    private function sendToUsers(Collection $users, Notification $notification, array $data): void
    {
        foreach ($users as $user) {
            try {
                // Store in-app notification
                $user->notifications()->create([
                    'id' => $notification->id,
                    'type' => get_class($notification),
                    'data' => $notification->data,
                    'read_at' => null,
                ]);

                // Send email if user has email notifications enabled
                if ($user->notification_preferences['email_alerts'] ?? true) {
                    $this->sendEmailNotification($user, $notification, $data);
                }

                // Send SMS if configured and user prefers SMS for high priority
                if ($notification->priority === 'high' &&
                    ($user->notification_preferences['sms_alerts'] ?? false) &&
                    $user->phone) {
                    $this->sendSmsNotification($user, $notification);
                }

            } catch (Exception $e) {
                Log::error('Failed to send notification to user', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send email notification
     */
    private function sendEmailNotification(User $user, Notification $notification, array $data): void
    {
        try {
            Mail::to($user->email)->send(new SeoAlert($notification, $data));

            Log::info('Email notification sent', [
                'user_id' => $user->id,
                'notification_id' => $notification->id,
                'type' => $notification->type,
            ]);

        } catch (Exception $exception) {
            Log::error('Failed to send email notification', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send SMS notification (placeholder for SMS service integration)
     */
    private function sendSmsNotification(User $user, Notification $notification): void
    {
        try {
            // This would integrate with SMS service like Twilio
            // For now, just log the intent

            Log::info('SMS notification would be sent', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'notification_id' => $notification->id,
                'message' => $this->formatSmsMessage($notification),
            ]);

        } catch (Exception $exception) {
            Log::error('Failed to send SMS notification', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Format traffic change message
     */
    private function formatTrafficMessage(array $data): string
    {
        $change = $data['percentage_change'] ?? 0;
        $direction = $change > 0 ? 'increased' : 'decreased';
        $abs_change = abs($change);

        return sprintf('Organic traffic has %s by %s%% compared to the previous period. ', $direction, $abs_change).
               'Current traffic: '.number_format($data['current_traffic'] ?? 0).' clicks.';
    }

    /**
     * Format competitor movement message
     */
    private function formatCompetitorMessage(array $data): string
    {
        $domain = $data['competitor_domain'];
        $keywordCount = $data['keywords_improved'] ?? 0;

        return sprintf("Competitor %s has improved rankings on %s keywords you're tracking. ", $domain, $keywordCount).
               'Review their strategy to maintain competitive advantage.';
    }

    /**
     * Format SMS message (keep it short)
     */
    private function formatSmsMessage(Notification $notification): string
    {
        $message = $notification->title;

        if ($notification->type === 'position_alert') {
            $position = $notification->data['new_position'] ?? 0;
            $keyword = $notification->data['keyword'] ?? '';
            $message = sprintf("SEO Alert: '%s' now at position %s", $keyword, $position);
        }

        return mb_substr($message, 0, 160); // SMS character limit
    }

    /**
     * Prepare daily digest data
     */
    private function prepareDailyDigest(User $user): array
    {
        $projects = $user->tenant->projects()
            ->whereHas('keywords')
            ->with(['keywords' => function ($query): void {
                $query->where('is_active', true)
                    ->whereNotNull('latest_position');
            }])
            ->get();

        $digestData = [
            'user' => $user,
            'date' => now()->format('F j, Y'),
            'projects' => [],
            'summary' => [
                'total_projects' => $projects->count(),
                'total_keywords' => 0,
                'avg_position' => 0,
                'improvements' => 0,
                'declines' => 0,
            ],
        ];

        foreach ($projects as $project) {
            $projectSummary = [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'keyword_count' => $project->keywords->count(),
                'avg_position' => 0,
                'position_changes' => [],
                'alerts' => [],
            ];

            if ($project->keywords->isNotEmpty()) {
                $positions = $project->keywords->pluck('latest_position')->filter();
                $projectSummary['avg_position'] = $positions->isNotEmpty()
                    ? round($positions->avg(), 1)
                    : 0;

                $digestData['summary']['total_keywords'] += $project->keywords->count();
            }

            // Get recent position changes (last 24 hours)
            $recentChanges = $this->getRecentPositionChanges($project);
            $projectSummary['position_changes'] = $recentChanges;

            $digestData['summary']['improvements'] += collect($recentChanges)->where('change', '>', 0)->count();
            $digestData['summary']['declines'] += collect($recentChanges)->where('change', '<', 0)->count();

            // Get recent alerts for this project
            $projectSummary['alerts'] = $project->notifications()
                ->where('created_at', '>=', now()->subDay())
                ->where('priority', '!=', 'low')
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get()
                ->toArray();

            $digestData['projects'][] = $projectSummary;
        }

        // Calculate overall average position
        if ($digestData['summary']['total_keywords'] > 0) {
            $allPositions = collect($digestData['projects'])
                ->pluck('avg_position')
                ->filter();
            $digestData['summary']['avg_position'] = $allPositions->isNotEmpty()
                ? round($allPositions->avg(), 1)
                : 0;
        }

        return $digestData;
    }

    /**
     * Get recent position changes for a project
     */
    private function getRecentPositionChanges(Project $project, int $hours = 24): array
    {
        $changes = [];

        foreach ($project->keywords as $keyword) {
            $recentPositions = $keyword->positions()
                ->where('tracked_at', '>=', now()->subHours($hours))
                ->orderBy('tracked_at', 'desc')
                ->limit(2)
                ->get();

            if ($recentPositions->count() >= 2) {
                $latest = $recentPositions->first();
                $previous = $recentPositions->last();

                if ($latest->position && $previous->position) {
                    $change = $previous->position - $latest->position; // Positive = improvement

                    if (abs($change) >= 5) { // Only report significant changes
                        $changes[] = [
                            'keyword' => $keyword->term,
                            'old_position' => $previous->position,
                            'new_position' => $latest->position,
                            'change' => $change,
                            'change_type' => $change > 0 ? 'improvement' : 'decline',
                        ];
                    }
                }
            }
        }

        return $changes;
    }

    /**
     * Prepare weekly summary data
     */
    private function prepareWeeklySummary(Tenant $tenant): array
    {
        $projects = $tenant->projects()
            ->with(['keywords.positions' => function ($query): void {
                $query->where('tracked_at', '>=', now()->subWeek())
                    ->orderBy('tracked_at', 'desc');
            }])
            ->get();

        $summary = [
            'tenant' => $tenant,
            'week_ending' => now()->format('F j, Y'),
            'projects' => [],
            'overall' => [
                'total_keywords' => 0,
                'keywords_improved' => 0,
                'keywords_declined' => 0,
                'new_top_10' => 0,
                'lost_top_10' => 0,
            ],
        ];

        foreach ($projects as $project) {
            $projectData = $this->calculateWeeklyProjectPerformance($project);
            $summary['projects'][] = $projectData;

            // Aggregate overall metrics
            $summary['overall']['total_keywords'] += $projectData['keyword_count'];
            $summary['overall']['keywords_improved'] += $projectData['improvements'];
            $summary['overall']['keywords_declined'] += $projectData['declines'];
            $summary['overall']['new_top_10'] += $projectData['new_top_10'];
            $summary['overall']['lost_top_10'] += $projectData['lost_top_10'];
        }

        return $summary;
    }

    /**
     * Calculate weekly performance for a project
     */
    private function calculateWeeklyProjectPerformance(Project $project): array
    {
        $data = [
            'id' => $project->id,
            'name' => $project->name,
            'domain' => $project->domain,
            'keyword_count' => $project->keywords->count(),
            'improvements' => 0,
            'declines' => 0,
            'new_top_10' => 0,
            'lost_top_10' => 0,
            'avg_position_change' => 0,
        ];

        $positionChanges = [];

        foreach ($project->keywords as $keyword) {
            $weekPositions = $keyword->positions
                ->where('tracked_at', '>=', now()->subWeek())
                ->sortBy('tracked_at');

            if ($weekPositions->count() >= 2) {
                $oldest = $weekPositions->first();
                $newest = $weekPositions->last();

                if ($oldest->position && $newest->position) {
                    $change = $oldest->position - $newest->position; // Positive = improvement
                    $positionChanges[] = $change;

                    if ($change > 0) {
                        $data['improvements']++;
                    } elseif ($change < 0) {
                        $data['declines']++;
                    }

                    // Check top 10 movements
                    if ($newest->position <= 10 && $oldest->position > 10) {
                        $data['new_top_10']++;
                    } elseif ($oldest->position <= 10 && $newest->position > 10) {
                        $data['lost_top_10']++;
                    }
                }
            }
        }

        $data['avg_position_change'] = $positionChanges === []
            ? 0
            : round(array_sum($positionChanges) / count($positionChanges), 2);

        return $data;
    }
}
