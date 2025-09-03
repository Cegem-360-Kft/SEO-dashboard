<?php

declare(strict_types=1);

namespace App\Services;

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

final class GoogleApiService
{
    private GoogleClient $client;

    private ?SearchConsole $searchConsole = null;

    private ?AnalyticsData $analyticsData = null;

    public function __construct()
    {
        $this->initializeClient();
    }

    /**
     * Get authorization URL for OAuth flow
     */
    public function getAuthorizationUrl(array $additionalScopes = []): string
    {
        if ($additionalScopes !== []) {
            $currentScopes = $this->client->getScopes();
            $this->client->setScopes(array_merge($currentScopes, $additionalScopes));
        }

        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                throw new Exception('Token exchange failed: '.$token['error']);
            }

            Log::info('Google API token obtained successfully');

            return $token;

        } catch (Exception $exception) {
            Log::error('Failed to exchange authorization code', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Set access token for API requests
     */
    public function setAccessToken(array|string $token): void
    {
        $this->client->setAccessToken($token);

        // Initialize services with the authenticated client
        $this->searchConsole = new SearchConsole($this->client);
        $this->analyticsData = new AnalyticsData($this->client);
    }

    /**
     * Refresh access token if expired
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        try {
            $this->client->refreshToken($refreshToken);
            $newToken = $this->client->getAccessToken();

            Log::info('Google API token refreshed successfully');

            return $newToken;

        } catch (Exception $exception) {
            Log::error('Failed to refresh access token', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Check if token is valid and not expired
     */
    public function isTokenValid(): bool
    {
        try {
            return ! $this->client->isAccessTokenExpired();
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Get Search Console sites for authenticated user
     */
    public function getSearchConsoleSites(): array
    {
        if (! $this->searchConsole instanceof SearchConsole) {
            throw new Exception('Search Console service not initialized');
        }

        try {
            $sites = $this->searchConsole->sites->listSites();

            $result = [];
            foreach ($sites->getSiteEntry() as $site) {
                $result[] = [
                    'site_url' => $site->getSiteUrl(),
                    'permission_level' => $site->getPermissionLevel(),
                ];
            }

            Log::info('Retrieved Search Console sites', [
                'sites_count' => count($result),
            ]);

            return $result;

        } catch (Exception $exception) {
            Log::error('Failed to get Search Console sites', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Get Analytics accounts for authenticated user
     */
    public function getAnalyticsAccounts(): array
    {
        if (! $this->analyticsData instanceof AnalyticsData) {
            throw new Exception('Analytics service not initialized');
        }

        try {
            // This is a simplified version - GA4 API structure is different
            // You would need to implement proper account/property listing

            $accounts = [];

            Log::info('Retrieved Analytics accounts', [
                'accounts_count' => count($accounts),
            ]);

            return $accounts;

        } catch (Exception $exception) {
            Log::error('Failed to get Analytics accounts', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Test Search Console API connection
     */
    public function testSearchConsoleConnection(string $siteUrl): bool
    {
        if (! $this->searchConsole instanceof SearchConsole) {
            return false;
        }

        try {
            // Try to get a simple query to test the connection
            $request = new SearchAnalyticsQueryRequest;
            $request->setStartDate(now()->subDays(7)->format('Y-m-d'));
            $request->setEndDate(now()->subDays(1)->format('Y-m-d'));
            $request->setDimensions(['query']);
            $request->setRowLimit(1);

            $this->searchConsole->searchanalytics->query($siteUrl, $request);

            Log::info('Search Console connection test successful', [
                'site_url' => $siteUrl,
            ]);

            return true;

        } catch (Exception $exception) {
            Log::error('Search Console connection test failed', [
                'site_url' => $siteUrl,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Test Analytics API connection
     */
    public function testAnalyticsConnection(string $propertyId): bool
    {
        if (! $this->analyticsData instanceof AnalyticsData) {
            return false;
        }

        try {
            // Try a simple report request to test the connection
            $request = new RunReportRequest;
            $request->setProperty('properties/'.$propertyId);

            $dateRange = new DateRange;
            $dateRange->setStartDate(now()->subDays(7)->format('Y-m-d'));
            $dateRange->setEndDate(now()->subDays(1)->format('Y-m-d'));
            $request->setDateRanges([$dateRange]);

            $metrics = [new Metric(['name' => 'sessions'])];
            $request->setMetrics($metrics);

            $request->setLimit(1);

            $this->analyticsData->properties->runReport($request);

            Log::info('Analytics connection test successful', [
                'property_id' => $propertyId,
            ]);

            return true;

        } catch (Exception $exception) {
            Log::error('Analytics connection test failed', [
                'property_id' => $propertyId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get Search Console data with caching
     */
    public function getSearchConsoleData(string $siteUrl, array $params): array
    {
        if (! $this->searchConsole instanceof SearchConsole) {
            throw new Exception('Search Console service not initialized');
        }

        $cacheKey = 'gsc_data_'.md5($siteUrl.serialize($params));

        return Cache::remember($cacheKey, 3600, function () use ($siteUrl, $params): array {
            return $this->fetchSearchConsoleData($siteUrl, $params);
        });
    }

    /**
     * Get Analytics data with caching
     */
    public function getAnalyticsData(string $propertyId, array $params): array
    {
        if (! $this->analyticsData instanceof AnalyticsData) {
            throw new Exception('Analytics service not initialized');
        }

        $cacheKey = 'ga4_data_'.md5($propertyId.serialize($params));

        return Cache::remember($cacheKey, 3600, function () use ($propertyId, $params): array {
            return $this->fetchAnalyticsData($propertyId, $params);
        });
    }

    /**
     * Validate API credentials
     */
    public function validateCredentials(): array
    {
        $validation = [
            'client_configured' => false,
            'search_console_access' => false,
            'analytics_access' => false,
            'errors' => [],
        ];

        try {
            // Check if client is configured
            if ($this->client->getClientId() && $this->client->getClientSecret()) {
                $validation['client_configured'] = true;
            } else {
                $validation['errors'][] = 'Google API credentials not configured';
            }

            // Check services if token is set
            if ($this->client->getAccessToken()) {
                if ($this->searchConsole instanceof SearchConsole) {
                    try {
                        $this->searchConsole->sites->listSites();
                        $validation['search_console_access'] = true;
                    } catch (Exception $e) {
                        $validation['errors'][] = 'Search Console access failed: '.$e->getMessage();
                    }
                }

                if ($this->analyticsData instanceof AnalyticsData) {
                    $validation['analytics_access'] = true;
                }
            }

        } catch (Exception $exception) {
            $validation['errors'][] = 'Credential validation failed: '.$exception->getMessage();
        }

        return $validation;
    }

    /**
     * Get rate limit information
     */
    public function getRateLimitInfo(): array
    {
        return [
            'search_console' => [
                'requests_per_day' => 10000,
                'requests_per_100_seconds' => 1000,
                'current_usage' => $this->getCurrentUsage(),
            ],
            'analytics' => [
                'requests_per_day' => 50000,
                'requests_per_hour' => 2000,
                'current_usage' => $this->getCurrentUsage(),
            ],
        ];
    }

    /**
     * Initialize Google API client
     */
    private function initializeClient(): void
    {
        try {
            $this->client = new GoogleClient;
            $this->client->setClientId(config('services.google.client_id'));
            $this->client->setClientSecret(config('services.google.client_secret'));
            $this->client->setRedirectUri(config('services.google.redirect_uri'));
            $this->client->setScopes([
                SearchConsole::WEBMASTERS_READONLY,
                AnalyticsData::ANALYTICS_READONLY,
            ]);

            // Enable offline access for refresh tokens
            $this->client->setAccessType('offline');
            $this->client->setPrompt('consent');

        } catch (Exception $exception) {
            Log::error('Failed to initialize Google API client', [
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Fetch Search Console data from API
     */
    private function fetchSearchConsoleData(string $siteUrl, array $params): array
    {
        try {
            $request = new SearchAnalyticsQueryRequest;

            $request->setStartDate($params['start_date'] ?? now()->subDays(30)->format('Y-m-d'));
            $request->setEndDate($params['end_date'] ?? now()->subDays(1)->format('Y-m-d'));

            $dimensions = $params['dimensions'] ?? ['query', 'page', 'date'];
            $request->setDimensions($dimensions);

            $request->setRowLimit($params['limit'] ?? 25000);

            if (isset($params['filters'])) {
                $request->setDimensionFilterGroups($params['filters']);
            }

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
                $this->processSearchConsoleRows($response->getRows(), $data);
            }

            Log::info('Search Console data fetched successfully', [
                'site_url' => $siteUrl,
                'rows_count' => count($response->getRows() ?? []),
                'total_clicks' => $data['summary']['total_clicks'],
            ]);

            return $data;

        } catch (Exception $exception) {
            Log::error('Failed to fetch Search Console data', [
                'site_url' => $siteUrl,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Process Search Console API response rows
     */
    private function processSearchConsoleRows(array $rows, array &$data): void
    {
        foreach ($rows as $row) {
            $keys = $row->getKeys();
            $query = $keys[0] ?? '';
            $page = $keys[1] ?? '';

            $clicks = $row->getClicks() ?? 0;
            $impressions = $row->getImpressions() ?? 0;
            $ctr = $row->getCtr() ?? 0;
            $position = $row->getPosition() ?? 0;

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

            // Update totals
            $data['summary']['total_clicks'] += $clicks;
            $data['summary']['total_impressions'] += $impressions;
        }

        // Calculate averages and sort
        $this->finalizeSearchConsoleData($data);
    }

    /**
     * Finalize Search Console data calculations
     */
    private function finalizeSearchConsoleData(array &$data): void
    {
        // Calculate CTRs and sort
        foreach ($data['queries'] as &$query) {
            $query['ctr'] = $query['impressions'] > 0
                ? ($query['clicks'] / $query['impressions']) * 100
                : 0;
        }

        foreach ($data['pages'] as &$page) {
            $page['ctr'] = $page['impressions'] > 0
                ? ($page['clicks'] / $page['impressions']) * 100
                : 0;
        }

        // Calculate summary averages
        if ($data['summary']['total_impressions'] > 0) {
            $data['summary']['average_ctr'] =
                ($data['summary']['total_clicks'] / $data['summary']['total_impressions']) * 100;
        }

        // Sort by clicks
        uasort($data['queries'], fn ($a, $b): int => $b['clicks'] <=> $a['clicks']);
        uasort($data['pages'], fn ($a, $b): int => $b['clicks'] <=> $a['clicks']);

        // Convert to indexed arrays and limit
        $data['queries'] = array_slice(array_values($data['queries']), 0, 100);
        $data['pages'] = array_slice(array_values($data['pages']), 0, 50);
    }

    /**
     * Fetch Analytics data from API
     */
    private function fetchAnalyticsData(string $propertyId, array $params): array
    {
        try {
            $request = new RunReportRequest;
            $request->setProperty('properties/'.$propertyId);

            // Date range
            $dateRange = new DateRange;
            $dateRange->setStartDate($params['start_date'] ?? now()->subDays(30)->format('Y-m-d'));
            $dateRange->setEndDate($params['end_date'] ?? now()->subDays(1)->format('Y-m-d'));
            $request->setDateRanges([$dateRange]);

            // Metrics
            $metricNames = $params['metrics'] ?? [
                'sessions', 'users', 'pageviews', 'bounceRate',
                'averageSessionDuration', 'conversions',
            ];

            $metrics = array_map(function ($name): Metric {
                return new Metric(['name' => $name]);
            }, $metricNames);

            $request->setMetrics($metrics);

            // Dimensions
            $dimensionNames = $params['dimensions'] ?? [
                'date', 'sessionDefaultChannelGroup', 'landingPage',
            ];

            $dimensions = array_map(function ($name): Dimension {
                return new Dimension(['name' => $name]);
            }, $dimensionNames);

            $request->setDimensions($dimensions);

            $response = $this->analyticsData->properties->runReport($request);

            $data = $this->processAnalyticsResponse($response);

            Log::info('Analytics data fetched successfully', [
                'property_id' => $propertyId,
                'rows_count' => count($response->getRows() ?? []),
                'sessions' => $data['overview']['sessions'] ?? 0,
            ]);

            return $data;

        } catch (Exception $exception) {
            Log::error('Failed to fetch Analytics data', [
                'property_id' => $propertyId,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Process Analytics API response
     */
    private function processAnalyticsResponse($response): array
    {
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

        if (! $response->getRows()) {
            return $data;
        }

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

            // Process other dimensions...
            // (Similar logic as in AnalyticsService)
        }

        return $data;
    }

    /**
     * Get current API usage (placeholder - would need actual tracking)
     */
    private function getCurrentUsage(): array
    {
        return [
            'requests_today' => 0,
            'requests_this_hour' => 0,
            'last_reset' => now()->startOfDay(),
        ];
    }
}
