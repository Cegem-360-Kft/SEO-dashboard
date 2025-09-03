<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Keyword;
use App\Models\Project;
use Exception;
use Google\Client as GoogleClient;
use Google\Service\AnalyticsData;
use Google\Service\AnalyticsData\DateRange;
use Google\Service\AnalyticsData\Dimension;
use Google\Service\AnalyticsData\Metric;
use Google\Service\AnalyticsData\RunReportRequest;
use Google\Service\SearchConsole;
use Google\Service\SearchConsole\SearchAnalyticsQueryRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class AnalyticsService
{
    private ?GoogleClient $googleClient = null;

    private ?SearchConsole $searchConsole = null;

    private ?AnalyticsData $analyticsData = null;

    public function __construct()
    {
        $this->initializeGoogleClient();
    }

    /**
     * Set access token for authenticated requests
     */
    public function setAccessToken(string $accessToken): void
    {
        if ($this->googleClient instanceof GoogleClient) {
            $this->googleClient->setAccessToken($accessToken);
        }
    }

    /**
     * Get Google Search Console data for a project
     */
    public function getSearchConsoleData(Project $project, array $options = []): array
    {
        if (! $this->searchConsole instanceof SearchConsole) {
            return ['error' => 'Search Console client not initialized'];
        }

        $siteUrl = $project->domain;
        $startDate = $options['start_date'] ?? now()->subDays(30)->format('Y-m-d');
        $endDate = $options['end_date'] ?? now()->format('Y-m-d');

        $cacheKey = sprintf('gsc_data:%s:%s:%s', $project->id, $startDate, $endDate);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $request = new SearchAnalyticsQueryRequest;
            $request->setStartDate($startDate);
            $request->setEndDate($endDate);
            $request->setDimensions(['query', 'page', 'date']);
            $request->setRowLimit(25000);

            $response = $this->searchConsole->searchanalytics->query($siteUrl, $request);

            $data = [
                'queries' => [],
                'pages' => [],
                'summary' => [
                    'total_clicks' => 0,
                    'total_impressions' => 0,
                    'average_ctr' => 0,
                    'average_position' => 0,
                ],
            ];

            if ($response->getRows()) {
                foreach ($response->getRows() as $row) {
                    $keys = $row->getKeys();
                    $query = $keys[0];
                    $page = $keys[1];
                    $date = $keys[2];

                    $clicks = $row->getClicks();
                    $impressions = $row->getImpressions();
                    $ctr = $row->getCtr();
                    $position = $row->getPosition();

                    // Aggregate query data
                    if (! isset($data['queries'][$query])) {
                        $data['queries'][$query] = [
                            'query' => $query,
                            'clicks' => 0,
                            'impressions' => 0,
                            'ctr' => 0,
                            'position' => 0,
                        ];
                    }

                    $data['queries'][$query]['clicks'] += $clicks;
                    $data['queries'][$query]['impressions'] += $impressions;
                    $data['queries'][$query]['ctr'] = $data['queries'][$query]['clicks'] > 0
                        ? ($data['queries'][$query]['clicks'] / $data['queries'][$query]['impressions']) * 100
                        : 0;
                    $data['queries'][$query]['position'] = ($data['queries'][$query]['position'] + $position) / 2;

                    // Aggregate page data
                    if (! isset($data['pages'][$page])) {
                        $data['pages'][$page] = [
                            'page' => $page,
                            'clicks' => 0,
                            'impressions' => 0,
                            'ctr' => 0,
                            'position' => 0,
                        ];
                    }

                    $data['pages'][$page]['clicks'] += $clicks;
                    $data['pages'][$page]['impressions'] += $impressions;
                    $data['pages'][$page]['ctr'] = $data['pages'][$page]['clicks'] > 0
                        ? ($data['pages'][$page]['clicks'] / $data['pages'][$page]['impressions']) * 100
                        : 0;
                    $data['pages'][$page]['position'] = ($data['pages'][$page]['position'] + $position) / 2;

                    // Update totals
                    $data['summary']['total_clicks'] += $clicks;
                    $data['summary']['total_impressions'] += $impressions;
                }

                // Calculate averages
                if ($data['summary']['total_impressions'] > 0) {
                    $data['summary']['average_ctr'] =
                        ($data['summary']['total_clicks'] / $data['summary']['total_impressions']) * 100;
                }

                $totalPosition = array_sum(array_column($data['queries'], 'position'));
                $queryCount = count($data['queries']);
                $data['summary']['average_position'] = $queryCount > 0 ? $totalPosition / $queryCount : 0;

                // Sort by performance
                usort($data['queries'], fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);
                usort($data['pages'], fn (array $a, array $b): int => $b['clicks'] <=> $a['clicks']);

                $data['queries'] = array_slice($data['queries'], 0, 100);
                $data['pages'] = array_slice($data['pages'], 0, 50);
            }

            Cache::put($cacheKey, $data, 7200); // Cache for 2 hours

            return $data;

        } catch (Exception $exception) {
            Log::error('Failed to fetch Search Console data', [
                'error' => $exception->getMessage(),
                'project_id' => $project->id,
            ]);

            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * Get Google Analytics 4 data for a project
     */
    public function getAnalyticsData(Project $project, array $options = []): array
    {
        if (! $this->analyticsData instanceof AnalyticsData) {
            return ['error' => 'Analytics client not initialized'];
        }

        $propertyId = $project->ga4_property_id;
        if (! $propertyId) {
            return ['error' => 'GA4 property ID not configured'];
        }

        $startDate = $options['start_date'] ?? now()->subDays(30)->format('Y-m-d');
        $endDate = $options['end_date'] ?? now()->format('Y-m-d');

        $cacheKey = sprintf('ga4_data:%s:%s:%s', $project->id, $startDate, $endDate);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $request = new RunReportRequest;
            $request->setProperty('properties/'.$propertyId);

            // Date range
            $dateRange = new DateRange;
            $dateRange->setStartDate($startDate);
            $dateRange->setEndDate($endDate);
            $request->setDateRanges([$dateRange]);

            // Metrics
            $metrics = [
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'users']),
                new Metric(['name' => 'pageviews']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'conversions']),
            ];
            $request->setMetrics($metrics);

            // Dimensions
            $dimensions = [
                new Dimension(['name' => 'date']),
                new Dimension(['name' => 'sessionDefaultChannelGroup']),
                new Dimension(['name' => 'landingPage']),
            ];
            $request->setDimensions($dimensions);

            $response = $this->analyticsData->properties->runReport($request);

            $data = [
                'overview' => [
                    'sessions' => 0,
                    'users' => 0,
                    'pageviews' => 0,
                    'bounce_rate' => 0,
                    'avg_session_duration' => 0,
                    'conversions' => 0,
                ],
                'traffic_sources' => [],
                'landing_pages' => [],
                'daily_data' => [],
            ];

            if ($response->getRows()) {
                foreach ($response->getRows() as $row) {
                    $dimensions = $row->getDimensionValues();
                    $metrics = $row->getMetricValues();

                    $date = $dimensions[0]->getValue();
                    $channelGroup = $dimensions[1]->getValue();
                    $landingPage = $dimensions[2]->getValue();

                    $sessions = (float) $metrics[0]->getValue();
                    $users = (float) $metrics[1]->getValue();
                    $pageviews = (float) $metrics[2]->getValue();
                    $bounceRate = (float) $metrics[3]->getValue();
                    $avgSessionDuration = (float) $metrics[4]->getValue();
                    $conversions = (float) $metrics[5]->getValue();

                    // Aggregate overview data
                    $data['overview']['sessions'] += $sessions;
                    $data['overview']['users'] += $users;
                    $data['overview']['pageviews'] += $pageviews;
                    $data['overview']['conversions'] += $conversions;

                    // Traffic sources
                    if (! isset($data['traffic_sources'][$channelGroup])) {
                        $data['traffic_sources'][$channelGroup] = [
                            'channel' => $channelGroup,
                            'sessions' => 0,
                            'users' => 0,
                            'conversions' => 0,
                        ];
                    }

                    $data['traffic_sources'][$channelGroup]['sessions'] += $sessions;
                    $data['traffic_sources'][$channelGroup]['users'] += $users;
                    $data['traffic_sources'][$channelGroup]['conversions'] += $conversions;

                    // Landing pages
                    if (! isset($data['landing_pages'][$landingPage])) {
                        $data['landing_pages'][$landingPage] = [
                            'page' => $landingPage,
                            'sessions' => 0,
                            'users' => 0,
                            'bounce_rate' => 0,
                            'conversions' => 0,
                        ];
                    }

                    $data['landing_pages'][$landingPage]['sessions'] += $sessions;
                    $data['landing_pages'][$landingPage]['users'] += $users;
                    $data['landing_pages'][$landingPage]['bounce_rate'] = $bounceRate;
                    $data['landing_pages'][$landingPage]['conversions'] += $conversions;

                    // Daily data
                    if (! isset($data['daily_data'][$date])) {
                        $data['daily_data'][$date] = [
                            'date' => $date,
                            'sessions' => 0,
                            'users' => 0,
                            'pageviews' => 0,
                            'conversions' => 0,
                        ];
                    }

                    $data['daily_data'][$date]['sessions'] += $sessions;
                    $data['daily_data'][$date]['users'] += $users;
                    $data['daily_data'][$date]['pageviews'] += $pageviews;
                    $data['daily_data'][$date]['conversions'] += $conversions;
                }

                // Calculate averages
                $totalRows = count($response->getRows());
                if ($totalRows > 0) {
                    $data['overview']['bounce_rate'] = array_sum(array_column($response->getRows(), function ($row): float {
                        return (float) $row->getMetricValues()[3]->getValue();
                    })) / $totalRows;

                    $data['overview']['avg_session_duration'] = array_sum(array_column($response->getRows(), function ($row): float {
                        return (float) $row->getMetricValues()[4]->getValue();
                    })) / $totalRows;
                }

                // Sort arrays
                usort($data['traffic_sources'], fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
                usort($data['landing_pages'], fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);
                ksort($data['daily_data']);

                $data['traffic_sources'] = array_slice(array_values($data['traffic_sources']), 0, 10);
                $data['landing_pages'] = array_slice(array_values($data['landing_pages']), 0, 20);
                $data['daily_data'] = array_values($data['daily_data']);
            }

            Cache::put($cacheKey, $data, 3600); // Cache for 1 hour

            return $data;

        } catch (Exception $exception) {
            Log::error('Failed to fetch Analytics data', [
                'error' => $exception->getMessage(),
                'project_id' => $project->id,
            ]);

            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * Get organic traffic data for keywords
     */
    public function getOrganicTrafficForKeywords(Project $project): array
    {
        $gscData = $this->getSearchConsoleData($project);

        if (isset($gscData['error'])) {
            return $gscData;
        }

        $keywordTraffic = [];
        $projectKeywords = $project->keywords->pluck('term')->toArray();

        foreach ($gscData['queries'] as $queryData) {
            $query = $queryData['query'];

            // Check if this query matches any of the project's keywords
            $matchedKeyword = $this->findMatchingKeyword($query, $projectKeywords);

            if ($matchedKeyword) {
                $keywordTraffic[] = [
                    'keyword' => $matchedKeyword,
                    'query' => $query,
                    'clicks' => $queryData['clicks'],
                    'impressions' => $queryData['impressions'],
                    'ctr' => $queryData['ctr'],
                    'position' => $queryData['position'],
                ];
            }
        }

        return $keywordTraffic;
    }

    /**
     * Calculate SEO performance metrics
     */
    public function calculateSeoMetrics(Project $project): array
    {
        $gscData = $this->getSearchConsoleData($project);
        $this->getAnalyticsData($project);

        $metrics = [
            'visibility' => 0,
            'organic_traffic' => 0,
            'average_position' => 0,
            'click_through_rate' => 0,
            'total_keywords' => $project->keywords->count(),
            'ranking_keywords' => 0,
            'top_10_keywords' => 0,
            'featured_snippets' => 0,
        ];

        if (! isset($gscData['error'])) {
            $metrics['organic_traffic'] = $gscData['summary']['total_clicks'];
            $metrics['average_position'] = round($gscData['summary']['average_position'], 2);
            $metrics['click_through_rate'] = round($gscData['summary']['average_ctr'], 2);
            $metrics['ranking_keywords'] = count($gscData['queries']);

            // Count top 10 keywords
            $topKeywords = array_filter($gscData['queries'], fn (array $q): bool => $q['position'] <= 10);
            $metrics['top_10_keywords'] = count($topKeywords);
        }

        // Calculate visibility score (simplified)
        if ($metrics['total_keywords'] > 0 && $metrics['ranking_keywords'] > 0) {
            $visibility = ($metrics['ranking_keywords'] / $metrics['total_keywords']) * 100;
            $positionWeight = max(0, (100 - $metrics['average_position']) / 100);
            $metrics['visibility'] = round($visibility * $positionWeight, 2);
        }

        // Count featured snippets from recent position tracking
        $featuredSnippets = $project->keywords()
            ->whereHas('positions.serpFeatures', function ($query): void {
                $query->where('feature_type', 'featured_snippet')
                    ->where('created_at', '>=', now()->subDays(30));
            })
            ->count();
        $metrics['featured_snippets'] = $featuredSnippets;

        return $metrics;
    }

    /**
     * Generate comprehensive SEO report data
     */
    public function generateSeoReportData(Project $project, array $options = []): array
    {
        return [
            'project' => $project->load('keywords', 'competitors'),
            'metrics' => $this->calculateSeoMetrics($project),
            'search_console' => $this->getSearchConsoleData($project, $options),
            'analytics' => $this->getAnalyticsData($project, $options),
            'keyword_traffic' => $this->getOrganicTrafficForKeywords($project),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Initialize Google API clients
     */
    private function initializeGoogleClient(): void
    {
        try {
            if (! config('services.google.client_id')) {
                Log::warning('Google API credentials not configured');

                return;
            }

            $this->googleClient = new GoogleClient;
            $this->googleClient->setClientId(config('services.google.client_id'));
            $this->googleClient->setClientSecret(config('services.google.client_secret'));
            $this->googleClient->setRedirectUri(config('services.google.redirect_uri'));
            $this->googleClient->setScopes([
                SearchConsole::WEBMASTERS_READONLY,
                AnalyticsData::ANALYTICS_READONLY,
            ]);

            $this->searchConsole = new SearchConsole($this->googleClient);
            $this->analyticsData = new AnalyticsData($this->googleClient);

        } catch (Exception $exception) {
            Log::error('Failed to initialize Google API client', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Find matching keyword for a search query
     */
    private function findMatchingKeyword(string $query, array $keywords): ?string
    {
        $query = mb_strtolower(mb_trim($query));

        // Exact match first
        if (in_array($query, array_map('strtolower', $keywords))) {
            return $keywords[array_search($query, array_map('strtolower', $keywords), true)];
        }

        // Partial match
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(mb_trim($keyword));
            if (mb_strpos($query, $keyword) !== false || mb_strpos($keyword, $query) !== false) {
                return $keyword;
            }
        }

        return null;
    }
}
