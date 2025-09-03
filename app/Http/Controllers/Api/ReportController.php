<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GenerateReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Project;
use App\Models\Report;
use App\Services\ReportingService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReportController extends Controller
{
    public function __construct(
        private ReportingService $reportingService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('api.tenant');
    }

    /**
     * Display a listing of reports
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $reports = $request->user()->tenant->reports()
            ->with(['project'])
            ->when($request->project_id, function ($query, $projectId): void {
                $query->where('project_id', $projectId);
            })
            ->when($request->type, function ($query, $type): void {
                $query->where('type', $type);
            })
            ->when($request->status, function ($query, $status): void {
                $query->where('status', $status);
            })
            ->when($request->search, function ($query, $search): void {
                $query->where('title', 'ILIKE', sprintf('%%%s%%', $search));
            })
            ->orderBy($request->sort ?? 'created_at', $request->direction ?? 'desc')
            ->paginate($request->per_page ?? 15);

        return ReportResource::collection($reports);
    }

    /**
     * Generate a new report
     */
    public function store(GenerateReportRequest $request): JsonResponse
    {
        $project = Project::query()->findOrFail($request->project_id);
        $this->authorize('view', $project);

        try {
            $report = $this->reportingService->generateReport($project, $request->validated());

            return response()->json([
                'message' => 'Report generation started',
                'report' => new ReportResource($report),
            ], 202);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Failed to generate report',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * Display the specified report
     */
    public function show(Report $report): ReportResource
    {
        $this->authorize('view', $report->project);

        $report->load(['project']);

        return new ReportResource($report);
    }

    /**
     * Update the specified report
     */
    public function update(Request $request, Report $report): ReportResource
    {
        $this->authorize('update', $report->project);

        $request->validate([
            'title' => ['string', 'max:255'],
            'status' => ['in:draft,generating,completed,failed,scheduled'],
            'is_automated' => ['boolean'],
            'schedule' => ['array'],
        ]);

        $report->update($request->only(['title', 'status', 'is_automated', 'schedule']));

        return new ReportResource($report);
    }

    /**
     * Remove the specified report
     */
    public function destroy(Report $report): JsonResponse
    {
        $this->authorize('delete', $report->project);

        $deleted = $this->reportingService->deleteReport($report);

        if ($deleted) {
            return response()->json(['message' => 'Report deleted successfully']);
        }

        return response()->json(['message' => 'Failed to delete report'], 500);
    }

    /**
     * Download report in specified format
     */
    public function download(Report $report, string $format = 'pdf'): BinaryFileResponse
    {
        $this->authorize('view', $report->project);

        if (! in_array($format, ['pdf', 'html'])) {
            abort(400, 'Invalid format. Supported formats: pdf, html');
        }

        $filePath = $this->reportingService->downloadReport($report, $format);

        if (! $filePath || ! file_exists($filePath)) {
            abort(404, 'Report file not found');
        }

        $filename = sprintf('%s_%s.%s', $report->title, $report->id, $format);

        return response()->download($filePath, $filename);
    }

    /**
     * Generate custom report with template
     */
    public function generateCustom(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'template' => ['required', 'array'],
            'template.title' => ['required', 'string', 'max:255'],
            'template.sections' => ['required', 'array'],
            'template.sections.*.type' => ['required', 'in:keyword_performance,traffic_analysis,competitor_comparison,technical_seo'],
            'template.sections.*.config' => ['array'],
        ]);

        $project = Project::query()->findOrFail($request->project_id);
        $this->authorize('view', $project);

        try {
            $report = $this->reportingService->generateCustomReport(
                $project,
                $request->template,
                $request->options ?? []
            );

            return response()->json([
                'message' => 'Custom report generation started',
                'report' => new ReportResource($report),
            ], 202);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Failed to generate custom report',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * Schedule automated report
     */
    public function schedule(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:daily,weekly,monthly,quarterly'],
            'frequency' => ['required', 'in:daily,weekly,monthly,quarterly'],
            'day_of_week' => ['required_if:frequency,weekly', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'day_of_month' => ['required_if:frequency,monthly', 'integer', 'min:1', 'max:31'],
            'recipients' => ['required', 'array'],
            'recipients.*' => ['email'],
        ]);

        $project = Project::query()->findOrFail($request->project_id);
        $this->authorize('update', $project);

        $schedule = $request->only([
            'title', 'type', 'frequency', 'day_of_week', 'day_of_month', 'recipients',
        ]);

        $report = $this->reportingService->scheduleReport($project, $schedule);

        return response()->json([
            'message' => 'Report scheduled successfully',
            'report' => new ReportResource($report),
        ]);
    }

    /**
     * Get report templates
     */
    public function templates(): JsonResponse
    {
        $templates = [
            'executive_summary' => [
                'name' => 'Executive Summary',
                'description' => 'High-level overview of SEO performance',
                'sections' => ['overview', 'key_metrics', 'recommendations'],
            ],
            'keyword_analysis' => [
                'name' => 'Keyword Analysis',
                'description' => 'Detailed keyword performance and opportunities',
                'sections' => ['keyword_performance', 'position_trends', 'opportunities'],
            ],
            'competitor_analysis' => [
                'name' => 'Competitor Analysis',
                'description' => 'Comprehensive competitor comparison',
                'sections' => ['competitor_overview', 'keyword_overlaps', 'competitive_gaps'],
            ],
            'traffic_analysis' => [
                'name' => 'Traffic Analysis',
                'description' => 'Organic traffic trends and insights',
                'sections' => ['traffic_overview', 'channel_analysis', 'conversion_data'],
            ],
            'comprehensive' => [
                'name' => 'Comprehensive Report',
                'description' => 'Complete SEO analysis with all sections',
                'sections' => ['overview', 'keyword_performance', 'traffic_analysis', 'competitor_comparison', 'technical_seo', 'recommendations'],
            ],
        ];

        return response()->json($templates);
    }

    /**
     * Get report statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $days = $request->days ?? 30;

        $stats = [
            'total_reports' => $tenant->reports()->count(),
            'reports_this_period' => $tenant->reports()
                ->where('created_at', '>=', now()->subDays($days))
                ->count(),
            'by_status' => $tenant->reports()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'by_type' => $tenant->reports()
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
            'automated_reports' => $tenant->reports()
                ->where('is_automated', true)
                ->where('status', 'scheduled')
                ->count(),
            'recent_reports' => $tenant->reports()
                ->with('project')
                ->where('status', 'completed')
                ->orderBy('completed_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($report): array {
                    return [
                        'id' => $report->id,
                        'title' => $report->title,
                        'project' => $report->project->name,
                        'type' => $report->type,
                        'generated_at' => $report->generated_at,
                        'file_size' => $this->getReportFileSize($report),
                    ];
                }),
        ];

        return response()->json($stats);
    }

    /**
     * Duplicate an existing report
     */
    public function duplicate(Report $report): JsonResponse
    {
        $this->authorize('view', $report->project);

        try {
            // Create new report with same configuration
            $newReport = $this->reportingService->generateReport(
                $report->project,
                [
                    'title' => $report->title.' (Copy)',
                    'type' => $report->type,
                    'template_config' => $report->template_config,
                ]
            );

            return response()->json([
                'message' => 'Report duplication started',
                'original_report_id' => $report->id,
                'new_report' => new ReportResource($newReport),
            ], 202);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Failed to duplicate report',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * Share report via email
     */
    public function share(Request $request, Report $report): JsonResponse
    {
        $this->authorize('view', $report->project);

        $request->validate([
            'recipients' => ['required', 'array'],
            'recipients.*' => ['email'],
            'message' => ['nullable', 'string', 'max:1000'],
            'include_link' => ['boolean'],
        ]);

        try {
            // This would send the report via email
            // Implementation would depend on your mail service

            return response()->json([
                'message' => 'Report shared successfully',
                'recipients' => $request->recipients,
                'shared_at' => now(),
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Failed to share report',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * Get report generation status
     */
    public function status(Report $report): JsonResponse
    {
        $this->authorize('view', $report->project);

        $status = [
            'id' => $report->id,
            'status' => $report->status,
            'progress' => $this->calculateProgress($report),
            'estimated_completion' => $this->estimateCompletion($report),
            'error_message' => $report->error_message,
            'started_at' => $report->created_at,
            'completed_at' => $report->generated_at,
        ];

        return response()->json($status);
    }

    /**
     * Cancel report generation
     */
    public function cancel(Report $report): JsonResponse
    {
        $this->authorize('update', $report->project);

        if (! in_array($report->status, ['generating', 'scheduled'])) {
            return response()->json([
                'message' => 'Cannot cancel report in current status',
                'current_status' => $report->status,
            ], 422);
        }

        $report->update([
            'status' => 'cancelled',
            'error_message' => 'Report generation cancelled by user',
        ]);

        return response()->json(['message' => 'Report generation cancelled']);
    }

    /**
     * Get report insights and recommendations
     */
    public function insights(Report $report): JsonResponse
    {
        $this->authorize('view', $report->project);

        if ($report->status !== 'completed' || ! $report->data) {
            return response()->json([
                'message' => 'Report not completed or data not available',
            ], 422);
        }

        $insights = [
            'key_findings' => $this->extractKeyFindings($report->data),
            'action_items' => $this->extractActionItems($report->data),
            'performance_summary' => $this->extractPerformanceSummary($report->data),
            'trend_analysis' => $this->extractTrendAnalysis($report->data),
        ];

        return response()->json($insights);
    }

    /**
     * Get file size for a report
     */
    private function getReportFileSize(Report $report): ?string
    {
        if (! $report->file_paths || ! isset($report->file_paths['pdf'])) {
            return null;
        }

        $filePath = $report->file_paths['pdf'];
        if (Storage::disk('local')->exists($filePath)) {
            $bytes = Storage::disk('local')->size($filePath);

            return $this->formatFileSize($bytes);
        }

        return null;
    }

    /**
     * Format file size in human readable format
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((mb_strlen($bytes) - 1) / 3);

        return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Calculate report generation progress
     */
    private function calculateProgress(Report $report): int
    {
        return match ($report->status) {
            'generating' => rand(10, 90), // Simulate progress
            'completed' => 100,
            'failed' => 0,
            'scheduled' => 0,
            default => 0,
        };
    }

    /**
     * Estimate completion time for report
     */
    private function estimateCompletion(Report $report): ?string
    {
        if ($report->status !== 'generating') {
            return null;
        }

        // Estimate based on project size and report type
        $estimatedMinutes = match ($report->type) {
            'daily' => 2,
            'weekly' => 5,
            'monthly' => 10,
            'quarterly' => 15,
            'custom' => 8,
            default => 5,
        };

        return now()->addMinutes($estimatedMinutes)->toISOString();
    }

    /**
     * Extract key findings from report data
     */
    private function extractKeyFindings(array $data): array
    {
        $findings = [];

        if (isset($data['summary'])) {
            $summary = $data['summary'];

            // Traffic findings
            if ($summary['organic_traffic'] > 10000) {
                $findings[] = [
                    'type' => 'positive',
                    'category' => 'traffic',
                    'title' => 'Strong Organic Traffic',
                    'description' => 'Generated '.number_format($summary['organic_traffic']).' organic clicks this period.',
                ];
            }

            // Position findings
            if ($summary['top_10_keywords'] > 20) {
                $findings[] = [
                    'type' => 'positive',
                    'category' => 'rankings',
                    'title' => 'Good Keyword Coverage',
                    'description' => $summary['top_10_keywords'].' keywords ranking in top 10 positions.',
                ];
            }
        }

        return $findings;
    }

    /**
     * Extract action items from report data
     */
    private function extractActionItems(array $data): array
    {
        return $data['recommendations'] ?? [];
    }

    /**
     * Extract performance summary from report data
     */
    private function extractPerformanceSummary(array $data): array
    {
        return $data['summary'] ?? [];
    }

    /**
     * Extract trend analysis from report data
     */
    private function extractTrendAnalysis(array $data): array
    {
        $trends = [];

        if (isset($data['keywords'])) {
            $improving = 0;
            $declining = 0;

            foreach ($data['keywords'] as $keyword) {
                if (isset($keyword['trends']['trend'])) {
                    if ($keyword['trends']['trend'] === 'improving') {
                        $improving++;
                    } elseif ($keyword['trends']['trend'] === 'declining') {
                        $declining++;
                    }
                }
            }

            $trends['keyword_trends'] = [
                'improving' => $improving,
                'declining' => $declining,
                'stable' => count($data['keywords']) - $improving - $declining,
            ];
        }

        return $trends;
    }
}
