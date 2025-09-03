<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Project;
use App\Models\Report;
use App\Services\NotificationService;
use App\Services\ReportingService;
use Carbon\Carbon;
use DateTimeImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class GenerateReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    // 10 minutes
    public int $maxExceptions = 2;

    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private Project $project,
        private array $options = [],
        private ?Report $report = null
    ) {
        // Use long-running queue for comprehensive reports
        if (($options['type'] ?? 'monthly') === 'comprehensive' ||
            count($options['sections'] ?? []) > 4) {
            $this->onQueue('long-running');
        } else {
            $this->onQueue('reports');
        }
    }

    /**
     * Execute the job.
     */
    public function handle(ReportingService $reportingService, NotificationService $notificationService): void
    {
        $startTime = microtime(true);

        try {
            Log::info('Starting report generation', [
                'project_id' => $this->project->id,
                'project_name' => $this->project->name,
                'report_id' => $this->report?->id,
                'options' => $this->options,
            ]);

            // Update report status if we have a report instance
            if ($this->report instanceof Report) {
                $this->report->update(['status' => 'generating']);
            }

            // Generate the report
            $report = $reportingService->generateReport($this->project, $this->options);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('Report generation completed', [
                'project_id' => $this->project->id,
                'report_id' => $report->id,
                'duration_seconds' => $duration,
                'status' => $report->status,
            ]);

            // Send notification about completed report
            if ($report->status === 'completed') {
                $this->sendCompletionNotification($report, $notificationService);

                // Schedule email delivery if recipients are specified
                if (! empty($this->options['email_recipients'])) {
                    $this->scheduleEmailDelivery($report, $this->options['email_recipients']);
                }
            }

        } catch (Exception $exception) {
            $duration = round(microtime(true) - $startTime, 2);

            Log::error('Report generation failed', [
                'project_id' => $this->project->id,
                'report_id' => $this->report?->id,
                'duration_seconds' => $duration,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // Update report status to failed
            if ($this->report instanceof Report) {
                $this->report->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);
            }

            // Send failure notification
            $this->sendFailureNotification($exception, $notificationService);

            throw $exception; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Report generation job permanently failed', [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'report_id' => $this->report?->id,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Update report status to failed
        if ($this->report instanceof Report) {
            $this->report->update([
                'status' => 'failed',
                'error_message' => 'Report generation permanently failed: '.$exception->getMessage(),
            ]);
        }

        // Send final failure notification
        try {
            $notificationService = app(NotificationService::class);
            $this->sendFailureNotification($exception, $notificationService);
        } catch (Exception $e) {
            Log::error('Failed to send final failure notification', [
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
        return [60, 300]; // 1 minute, 5 minutes
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return now()->addHour(); // Give up after 1 hour
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'report-generation',
            'project:'.$this->project->id,
            'tenant:'.$this->project->tenant_id,
            $this->report instanceof Report ? 'report:'.$this->report->id : 'ad-hoc-report',
        ];
    }

    /**
     * Send notification about completed report
     */
    private function sendCompletionNotification(Report $report, NotificationService $notificationService): void
    {
        try {
            $notificationService->sendReportNotification($this->project, $report);

            Log::info('Report completion notification sent', [
                'report_id' => $report->id,
                'project_id' => $this->project->id,
            ]);

        } catch (Exception $exception) {
            Log::error('Failed to send report completion notification', [
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send notification about failed report
     */
    private function sendFailureNotification(Exception $exception, NotificationService $notificationService): void
    {
        try {
            $alertData = [
                'type' => 'report_generation_failed',
                'priority' => 'medium',
                'title' => 'Report Generation Failed',
                'message' => sprintf('Failed to generate report for %s: ', $this->project->name).$exception->getMessage(),
                'error' => $exception->getMessage(),
                'project_id' => $this->project->id,
                'report_id' => $this->report?->id,
            ];

            $notificationService->sendReportNotification($this->project, (object) $alertData);

        } catch (Exception $e) {
            Log::error('Failed to send report failure notification', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Schedule email delivery of the report
     */
    private function scheduleEmailDelivery(Report $report, array $recipients): void
    {
        try {
            // If delivery is scheduled for later, dispatch a delayed job
            if (! empty($this->options['delivery_time'])) {
                $deliveryTime = Carbon::parse($this->options['delivery_time']);

                SendReportEmailJob::dispatch($report, $recipients)
                    ->delay($deliveryTime);

                Log::info('Scheduled report email delivery', [
                    'report_id' => $report->id,
                    'recipients' => $recipients,
                    'delivery_time' => $deliveryTime,
                ]);
            } else {
                // Send immediately
                SendReportEmailJob::dispatch($report, $recipients);

                Log::info('Queued immediate report email delivery', [
                    'report_id' => $report->id,
                    'recipients' => $recipients,
                ]);
            }

        } catch (Exception $exception) {
            Log::error('Failed to schedule report email delivery', [
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
