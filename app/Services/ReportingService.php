<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\Report;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ReportingService
{
    private AnalyticsService $analyticsService;

    private SerpTrackingService $serpTrackingService;

    private SEOCalculationService $seoCalculationService;

    public function __construct(
        AnalyticsService $analyticsService,
        SerpTrackingService $serpTrackingService,
        SEOCalculationService $seoCalculationService
    ) {
        $this->analyticsService = $analyticsService;
        $this->serpTrackingService = $serpTrackingService;
        $this->seoCalculationService = $seoCalculationService;
    }

    /**
     * Generate a comprehensive SEO report
     */
    public function generateReport(Project $project, array $options = []): Report
    {
        try {
            $reportType = $options['type'] ?? 'monthly';
            $startDate = $options['start_date'] ?? $this->getDefaultStartDate($reportType);
            $endDate = $options['end_date'] ?? now();

            $report = Report::query()->create([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'title' => $options['title'] ?? 'SEO Report - '.$project->name,
                'type' => $reportType,
                'period_start' => $startDate,
                'period_end' => $endDate,
                'status' => 'generating',
                'metadata' => [
                    'generated_by' => auth()->id(),
                    'options' => $options,
                ],
            ]);

            // Collect all report data
            $reportData = $this->collectReportData($project, $startDate, $endDate);

            // Generate report content
            $content = $this->generateReportContent($reportData);

            // Store report files
            $this->storeReportFiles($report, $content, $reportData);

            // Update report status
            $report->update([
                'status' => 'completed',
                'data' => $reportData,
                'generated_at' => now(),
            ]);

            Log::info('Report generated successfully', [
                'report_id' => $report->id,
                'project_id' => $project->id,
            ]);

            return $report;

        } catch (Exception $exception) {
            if (isset($report)) {
                $report->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            }

            Log::error('Failed to generate report', [
                'error' => $exception->getMessage(),
                'project_id' => $project->id,
            ]);

            throw $exception;
        }
    }

    /**
     * Schedule automated report generation
     */
    public function scheduleReport(Project $project, array $schedule): Report
    {
        $report = Report::query()->create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'title' => $schedule['title'] ?? 'Automated SEO Report - '.$project->name,
            'type' => $schedule['type'] ?? 'monthly',
            'status' => 'scheduled',
            'is_automated' => true,
            'schedule' => $schedule,
            'metadata' => [
                'created_by' => auth()->id(),
                'schedule_settings' => $schedule,
            ],
        ]);

        Log::info('Report scheduled', [
            'report_id' => $report->id,
            'project_id' => $project->id,
            'schedule' => $schedule,
        ]);

        return $report;
    }

    /**
     * Get reports for a tenant
     */
    public function getTenantReports(Tenant $tenant, array $filters = []): Collection
    {
        $query = $tenant->reports()->with('project');

        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['date_from'])) {
            $query->where('period_start', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('period_end', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Download report file
     */
    public function downloadReport(Report $report, string $format = 'pdf'): ?string
    {
        if (! $report->file_paths || ! isset($report->file_paths[$format])) {
            return null;
        }

        $filePath = $report->file_paths[$format];

        if (! Storage::disk('local')->exists($filePath)) {
            Log::warning('Report file not found', [
                'report_id' => $report->id,
                'format' => $format,
                'path' => $filePath,
            ]);

            return null;
        }

        return Storage::disk('local')->path($filePath);
    }

    /**
     * Delete report and associated files
     */
    public function deleteReport(Report $report): bool
    {
        try {
            // Delete associated files
            if ($report->file_paths) {
                foreach ($report->file_paths as $path) {
                    if (Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                    }
                }

                // Delete report directory if empty
                $reportDir = dirname($report->file_paths['html'] ?? '');
                if (Storage::disk('local')->exists($reportDir)) {
                    $files = Storage::disk('local')->files($reportDir);
                    if (empty($files)) {
                        Storage::disk('local')->deleteDirectory($reportDir);
                    }
                }
            }

            // Delete database record
            $report->delete();

            Log::info('Report deleted successfully', ['report_id' => $report->id]);

            return true;

        } catch (Exception $exception) {
            Log::error('Failed to delete report', [
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate report template with data
     */
    public function generateCustomReport(Project $project, array $template, array $options = []): Report
    {
        try {
            $report = Report::query()->create([
                'tenant_id' => $project->tenant_id,
                'project_id' => $project->id,
                'title' => $template['title'] ?? 'Custom SEO Report - '.$project->name,
                'type' => 'custom',
                'status' => 'generating',
                'template_config' => $template,
                'metadata' => [
                    'generated_by' => auth()->id(),
                    'template' => $template,
                    'options' => $options,
                ],
            ]);

            // Process custom template
            $reportData = $this->processCustomTemplate($project, $template);
            $content = $this->generateCustomContent($reportData, $template);

            $this->storeReportFiles($report, $content, $reportData);

            $report->update([
                'status' => 'completed',
                'data' => $reportData,
                'generated_at' => now(),
            ]);

            return $report;

        } catch (Exception $exception) {
            if (isset($report)) {
                $report->update(['status' => 'failed', 'error_message' => $exception->getMessage()]);
            }

            throw $exception;
        }
    }

    /**
     * Collect all data needed for the report
     */
    private function collectReportData(Project $project, $startDate, $endDate): array
    {
        $data = [
            'project_info' => [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'created_at' => $project->created_at,
            ],
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'duration_days' => $startDate->diffInDays($endDate),
            ],
            'summary' => [],
            'keywords' => [],
            'positions' => [],
            'analytics' => [],
            'competitors' => [],
            'recommendations' => [],
        ];

        // Get SEO metrics summary
        $data['summary'] = $this->analyticsService->calculateSeoMetrics($project);

        // Get keyword performance data
        $keywords = $project->keywords()->with(['positions' => function ($query) use ($startDate, $endDate): void {
            $query->whereBetween('tracked_at', [$startDate, $endDate])
                ->orderBy('tracked_at', 'desc');
        }])->get();

        $data['keywords'] = $keywords->map(function ($keyword): array {
            $trends = $this->serpTrackingService->calculatePositionTrends($keyword);

            return [
                'id' => $keyword->id,
                'term' => $keyword->term,
                'current_position' => $keyword->latest_position,
                'search_volume' => $keyword->search_volume,
                'difficulty' => $keyword->difficulty,
                'trends' => $trends,
                'position_history' => $keyword->positions->map(function ($position): array {
                    return [
                        'position' => $position->position,
                        'date' => $position->tracked_at->format('Y-m-d'),
                        'url' => $position->url,
                    ];
                }),
            ];
        })->toArray();

        // Get Analytics data if available
        if ($project->ga4_property_id) {
            $data['analytics'] = $this->analyticsService->getAnalyticsData($project, [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ]);
        }

        // Get Search Console data if available
        if ($project->gsc_property_url) {
            $data['search_console'] = $this->analyticsService->getSearchConsoleData($project, [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ]);
        }

        // Get competitor analysis
        $data['competitors'] = $this->getCompetitorAnalysis($project);

        // Generate SEO recommendations
        $data['recommendations'] = $this->seoCalculationService->generateRecommendations($project, $data);

        return $data;
    }

    /**
     * Generate report content in multiple formats
     */
    private function generateReportContent(array $data): array
    {
        return [
            // Generate HTML content
            'html' => view('reports.templates.seo-report', $data)->render(),
            // Generate executive summary
            'executive_summary' => $this->generateExecutiveSummary($data),
            // Generate key insights
            'insights' => $this->generateKeyInsights($data),
        ];
    }

    /**
     * Generate executive summary
     */
    private function generateExecutiveSummary(array $data): string
    {
        $summary = $data['summary'];
        $period = $data['period'];

        $text = "Executive Summary for {$data['project_info']['name']}\n\n";
        $text .= "Reporting Period: {$period['start']->format('M j, Y')} - {$period['end']->format('M j, Y')}\n\n";

        $text .= "Key Performance Indicators:\n";
        $text .= "• SEO Visibility Score: {$summary['visibility']}%\n";
        $text .= '• Total Organic Traffic: '.number_format($summary['organic_traffic'])." clicks\n";
        $text .= sprintf('• Average Position: %s%s', $summary['average_position'], PHP_EOL);
        $text .= sprintf('• Keywords in Top 10: %s%s', $summary['top_10_keywords'], PHP_EOL);
        $text .= "• Featured Snippets: {$summary['featured_snippets']}\n\n";

        // Performance assessment
        if ($summary['visibility'] >= 80) {
            $text .= "Performance Assessment: Excellent visibility with strong organic presence.\n";
        } elseif ($summary['visibility'] >= 60) {
            $text .= "Performance Assessment: Good visibility with opportunities for improvement.\n";
        } elseif ($summary['visibility'] >= 40) {
            $text .= "Performance Assessment: Moderate visibility requiring strategic optimization.\n";
        } else {
            $text .= "Performance Assessment: Low visibility requiring immediate attention.\n";
        }

        return $text;
    }

    /**
     * Generate key insights from the data
     */
    private function generateKeyInsights(array $data): array
    {
        $insights = [];
        $summary = $data['summary'];

        // Traffic insights
        if ($summary['organic_traffic'] > 10000) {
            $insights[] = [
                'type' => 'positive',
                'category' => 'Traffic',
                'title' => 'Strong Organic Traffic Performance',
                'description' => 'Generated '.number_format($summary['organic_traffic']).' organic clicks, indicating strong search visibility.',
            ];
        } elseif ($summary['organic_traffic'] < 1000) {
            $insights[] = [
                'type' => 'warning',
                'category' => 'Traffic',
                'title' => 'Low Organic Traffic',
                'description' => 'Only '.number_format($summary['organic_traffic']).' organic clicks. Consider expanding keyword strategy and content optimization.',
            ];
        }

        // Position insights
        if ($summary['average_position'] <= 10) {
            $insights[] = [
                'type' => 'positive',
                'category' => 'Rankings',
                'title' => 'Excellent Average Position',
                'description' => sprintf('Average position of %s indicates strong ranking performance.', $summary['average_position']),
            ];
        } elseif ($summary['average_position'] > 50) {
            $insights[] = [
                'type' => 'critical',
                'category' => 'Rankings',
                'title' => 'Poor Average Position',
                'description' => sprintf('Average position of %s requires immediate optimization efforts.', $summary['average_position']),
            ];
        }

        // Featured snippet insights
        if ($summary['featured_snippets'] > 0) {
            $insights[] = [
                'type' => 'positive',
                'category' => 'SERP Features',
                'title' => 'Featured Snippet Presence',
                'description' => sprintf('Secured %s featured snippets, providing enhanced visibility.', $summary['featured_snippets']),
            ];
        }

        // Keyword distribution insights
        $topKeywordPercentage = $summary['total_keywords'] > 0
            ? ($summary['top_10_keywords'] / $summary['total_keywords']) * 100
            : 0;

        if ($topKeywordPercentage >= 50) {
            $insights[] = [
                'type' => 'positive',
                'category' => 'Keyword Performance',
                'title' => 'Strong Keyword Rankings',
                'description' => number_format($topKeywordPercentage, 1).'% of keywords rank in top 10 positions.',
            ];
        } elseif ($topKeywordPercentage < 20) {
            $insights[] = [
                'type' => 'warning',
                'category' => 'Keyword Performance',
                'title' => 'Keyword Optimization Needed',
                'description' => 'Only '.number_format($topKeywordPercentage, 1).'% of keywords rank in top 10. Focus on content optimization.',
            ];
        }

        return $insights;
    }

    /**
     * Store report files (HTML, PDF, etc.)
     */
    private function storeReportFiles(Report $report, array $content, array $data): void
    {
        $reportPath = sprintf('reports/%s/%s', $report->tenant_id, $report->id);

        // Store HTML version
        Storage::disk('local')->put($reportPath.'/report.html', $content['html']);

        // Generate and store PDF version
        try {
            $pdf = Pdf::loadHTML($content['html'])
                ->setPaper('a4')
                ->setOptions(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true]);

            Storage::disk('local')->put($reportPath.'/report.pdf', $pdf->output());

            $report->update([
                'file_paths' => [
                    'html' => $reportPath.'/report.html',
                    'pdf' => $reportPath.'/report.pdf',
                ],
            ]);

        } catch (Exception $exception) {
            Log::error('Failed to generate PDF report', [
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);
        }

        // Store executive summary as text file
        Storage::disk('local')->put($reportPath.'/executive_summary.txt', $content['executive_summary']);

        // Store raw data as JSON
        Storage::disk('local')->put($reportPath.'/data.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get competitor analysis data
     */
    private function getCompetitorAnalysis(Project $project): array
    {
        $competitors = $project->competitors()->with('keywordPositions')->get();
        $analysis = [];

        foreach ($competitors as $competitor) {
            $competitorData = [
                'id' => $competitor->id,
                'domain' => $competitor->domain,
                'name' => $competitor->name,
                'average_position' => 0,
                'visibility_score' => 0,
                'keyword_overlaps' => 0,
                'better_positions' => 0,
            ];

            // Calculate competitor metrics
            if ($competitor->keywordPositions->count() > 0) {
                $competitorData['average_position'] = round(
                    $competitor->keywordPositions->avg('position'), 2
                );

                // Count keyword overlaps
                $projectKeywordTerms = $project->keywords->pluck('term');
                $competitorKeywordTerms = $competitor->keywordPositions
                    ->pluck('keyword.term')
                    ->filter();

                $competitorData['keyword_overlaps'] = $projectKeywordTerms
                    ->intersect($competitorKeywordTerms)
                    ->count();

                // Count positions where competitor is better
                $competitorData['better_positions'] = $this->countBetterPositions(
                    $project, $competitor
                );
            }

            $analysis[] = $competitorData;
        }

        // Sort by competitive threat (lower average position = higher threat)
        usort($analysis, fn (array $a, array $b): int => $a['average_position'] <=> $b['average_position']);

        return $analysis;
    }

    /**
     * Count positions where competitor ranks better
     */
    private function countBetterPositions(Project $project, $competitor): int
    {
        $count = 0;

        foreach ($project->keywords as $keyword) {
            $projectPosition = $keyword->latest_position;
            $competitorPosition = $competitor->keywordPositions()
                ->whereHas('keyword', function ($query) use ($keyword): void {
                    $query->where('term', $keyword->term);
                })
                ->latest('tracked_at')
                ->value('position');

            if ($competitorPosition && $projectPosition && $competitorPosition < $projectPosition) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get default start date based on report type
     */
    private function getDefaultStartDate(string $type)
    {
        return match ($type) {
            'daily' => now()->subDay(),
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            'quarterly' => now()->subMonths(3),
            'yearly' => now()->subYear(),
            default => now()->subMonth(),
        };
    }

    /**
     * Process custom report template
     */
    private function processCustomTemplate(Project $project, array $template): array
    {
        $data = [];

        foreach ($template['sections'] ?? [] as $section) {
            $sectionData = $this->processSectionData($project, $section);
            $data[$section['key']] = $sectionData;
        }

        return $data;
    }

    /**
     * Process individual section data
     */
    private function processSectionData(Project $project, array $section): array
    {
        return match ($section['type']) {
            'keyword_performance' => $this->getKeywordPerformanceData($project, $section['config'] ?? []),
            'traffic_analysis' => $this->getTrafficAnalysisData($project, $section['config'] ?? []),
            'competitor_comparison' => $this->getCompetitorComparisonData($project),
            'technical_seo' => $this->getTechnicalSeoData($project),
            default => []
        };
    }

    /**
     * Get keyword performance data for custom reports
     */
    private function getKeywordPerformanceData(Project $project, array $config): array
    {
        $keywords = $project->keywords()
            ->with('positions')
            ->when($config['limit'] ?? null, function ($query, $limit): void {
                $query->limit($limit);
            })
            ->get();

        return $keywords->map(function ($keyword): array {
            return [
                'term' => $keyword->term,
                'position' => $keyword->latest_position,
                'trends' => $this->serpTrackingService->calculatePositionTrends($keyword),
                'search_volume' => $keyword->search_volume,
            ];
        })->toArray();
    }

    /**
     * Get traffic analysis data for custom reports
     */
    private function getTrafficAnalysisData(Project $project, array $config): array
    {
        return $this->analyticsService->getAnalyticsData($project, $config);
    }

    /**
     * Get competitor comparison data for custom reports
     */
    private function getCompetitorComparisonData(Project $project): array
    {
        return $this->getCompetitorAnalysis($project);
    }

    /**
     * Get technical SEO data for custom reports
     */
    private function getTechnicalSeoData(Project $project): array
    {
        // This would integrate with technical SEO audit tools
        return [
            'crawl_errors' => 0,
            'page_speed' => null,
            'mobile_friendly' => null,
            'ssl_enabled' => str_starts_with($project->domain, 'https://'),
        ];
    }

    /**
     * Generate custom content from template
     */
    private function generateCustomContent(array $data, array $template): array
    {
        $content = [];

        // Use custom template view if specified
        $viewName = $template['view'] ?? 'reports.templates.custom-report';

        try {
            $content['html'] = view($viewName, ['data' => $data, 'template' => $template])->render();
        } catch (Exception $exception) {
            // Fallback to basic template
            $content['html'] = view('reports.templates.basic-report', ['data' => $data])->render();
        }

        return $content;
    }
}
