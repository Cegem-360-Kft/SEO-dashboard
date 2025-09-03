<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class SerpApiService
{
    private string $apiKey;
    private string $baseUrl;
    private array $rateLimits;
    private string $cachePrefix = 'serp_api_';

    public function __construct()
    {
        $this->apiKey = config('services.serp.api_key', '');
        $this->baseUrl = config('services.serp.base_url', 'https://serpapi.com/search.json');
        
        $this->rateLimits = [
            'requests_per_second' => 5,
            'requests_per_month' => 100000,
            'timeout' => 30,
        ];
    }

    /**
     * Search Google for keyword positions
     */
    public function searchGoogle(string $query, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'google',
            'q' => $query,
            'num' => 100,
            'hl' => 'en',
            'gl' => 'us',
        ], $params);

        $cacheKey = $this->cachePrefix . 'google_' . md5(serialize($searchParams));
        
        return Cache::remember($cacheKey, 3600, function () use ($searchParams) {
            return $this->makeRequest($searchParams);
        });
    }

    /**
     * Search Bing for keyword positions
     */
    public function searchBing(string $query, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'bing',
            'q' => $query,
            'count' => 50,
        ], $params);

        $cacheKey = $this->cachePrefix . 'bing_' . md5(serialize($searchParams));
        
        return Cache::remember($cacheKey, 3600, function () use ($searchParams) {
            return $this->makeRequest($searchParams);
        });
    }

    /**
     * Get local search results
     */
    public function searchLocal(string $query, string $location, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'google',
            'q' => $query,
            'location' => $location,
            'google_domain' => 'google.com',
            'num' => 20,
            'type' => 'search',
        ], $params);

        $cacheKey = $this->cachePrefix . 'local_' . md5(serialize($searchParams));
        
        return Cache::remember($cacheKey, 1800, function () use ($searchParams) {
            return $this->makeRequest($searchParams);
        });
    }

    /**
     * Get image search results
     */
    public function searchImages(string $query, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'google_images',
            'q' => $query,
            'num' => 20,
        ], $params);

        return $this->makeRequest($searchParams);
    }

    /**
     * Get video search results
     */
    public function searchVideos(string $query, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'youtube',
            'search_query' => $query,
        ], $params);

        return $this->makeRequest($searchParams);
    }

    /**
     * Get news search results
     */
    public function searchNews(string $query, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'google_news',
            'q' => $query,
            'num' => 20,
            'hl' => 'en',
            'gl' => 'us',
        ], $params);

        return $this->makeRequest($searchParams);
    }

    /**
     * Get shopping results
     */
    public function searchShopping(string $query, array $params = []): array
    {
        $searchParams = array_merge([
            'api_key' => $this->apiKey,
            'engine' => 'google_shopping',
            'q' => $query,
            'num' => 20,
        ], $params);

        return $this->makeRequest($searchParams);
    }

    /**
     * Find domain position in SERP results
     */
    public function findDomainPosition(array $serpResults, string $targetDomain): ?array
    {
        $organicResults = $serpResults['organic_results'] ?? [];
        $targetDomain = $this->normalizeDomain($targetDomain);

        foreach ($organicResults as $index => $result) {
            $resultDomain = $this->normalizeDomain($result['link'] ?? '');
            
            if ($resultDomain === $targetDomain) {
                return [
                    'position' => $index + 1,
                    'url' => $result['link'],
                    'title' => $result['title'] ?? '',
                    'snippet' => $result['snippet'] ?? '',
                    'displayed_link' => $result['displayed_link'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Get competitors from SERP results
     */
    public function getCompetitors(array $serpResults, string $excludeDomain, int $limit = 20): array
    {
        $organicResults = $serpResults['organic_results'] ?? [];
        $excludeDomain = $this->normalizeDomain($excludeDomain);
        $competitors = [];

        foreach ($organicResults as $index => $result) {
            if (count($competitors) >= $limit) {
                break;
            }

            $resultDomain = $this->normalizeDomain($result['link'] ?? '');
            
            if ($resultDomain && $resultDomain !== $excludeDomain) {
                $competitors[] = [
                    'position' => $index + 1,
                    'domain' => $resultDomain,
                    'url' => $result['link'],
                    'title' => $result['title'] ?? '',
                    'snippet' => $result['snippet'] ?? '',
                ];
            }
        }

        return $competitors;
    }

    /**
     * Extract SERP features
     */
    public function extractSerpFeatures(array $serpResults): array
    {
        $features = [];

        // Featured snippet
        if (isset($serpResults['answer_box'])) {
            $features['featured_snippet'] = [
                'type' => 'answer_box',
                'title' => $serpResults['answer_box']['title'] ?? '',
                'snippet' => $serpResults['answer_box']['snippet'] ?? '',
                'link' => $serpResults['answer_box']['link'] ?? '',
                'displayed_link' => $serpResults['answer_box']['displayed_link'] ?? '',
            ];
        }

        // Knowledge graph
        if (isset($serpResults['knowledge_graph'])) {
            $features['knowledge_graph'] = $serpResults['knowledge_graph'];
        }

        // Local pack
        if (isset($serpResults['local_results'])) {
            $features['local_pack'] = [
                'results' => $serpResults['local_results']['places'] ?? [],
            ];
        }

        // Images
        if (isset($serpResults['images_results'])) {
            $features['images'] = array_slice($serpResults['images_results'], 0, 5);
        }

        // Videos
        if (isset($serpResults['video_results'])) {
            $features['videos'] = array_slice($serpResults['video_results'], 0, 3);
        }

        // Shopping results
        if (isset($serpResults['shopping_results'])) {
            $features['shopping'] = array_slice($serpResults['shopping_results'], 0, 5);
        }

        // People also ask
        if (isset($serpResults['related_questions'])) {
            $features['people_also_ask'] = $serpResults['related_questions'];
        }

        // News results
        if (isset($serpResults['news_results'])) {
            $features['news'] = array_slice($serpResults['news_results'], 0, 3);
        }

        // Ads
        if (isset($serpResults['ads'])) {
            $features['ads'] = [
                'top' => $serpResults['ads'],
                'count' => count($serpResults['ads']),
            ];
        }

        return $features;
    }

    /**
     * Get search volume data (if available)
     */
    public function getSearchVolumeData(string $keyword): ?array
    {
        // SerpApi doesn't directly provide search volume
        // This would integrate with other services like Google Keyword Planner
        // or SEMrush API for search volume data
        
        return null;
    }

    /**
     * Batch search multiple keywords
     */
    public function batchSearch(array $keywords, array $baseParams = []): array
    {
        $results = [];
        $delayBetweenRequests = 1000000; // 1 second in microseconds

        foreach ($keywords as $keyword) {
            try {
                $results[$keyword] = $this->searchGoogle($keyword, $baseParams);
                
                // Rate limiting
                if (count($keywords) > 1) {
                    usleep($delayBetweenRequests);
                }
                
            } catch (Exception $e) {
                Log::error("Batch search failed for keyword: {$keyword}", [
                    'error' => $e->getMessage()
                ]);
                
                $results[$keyword] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Make HTTP request to SERP API
     */
    private function makeRequest(array $params): array
    {
        if (empty($this->apiKey)) {
            throw new Exception('SERP API key not configured');
        }

        try {
            // Check rate limits
            $this->checkRateLimit();

            $response = Http::timeout($this->rateLimits['timeout'])
                ->get($this->baseUrl, $params);

            if (!$response->successful()) {
                throw new Exception('SERP API request failed: ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();

            if (isset($data['error'])) {
                throw new Exception('SERP API error: ' . $data['error']);
            }

            // Track API usage
            $this->trackApiUsage();

            Log::info('SERP API request successful', [
                'query' => $params['q'] ?? 'unknown',
                'engine' => $params['engine'] ?? 'google',
                'results_count' => count($data['organic_results'] ?? []),
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error('SERP API request failed', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            throw $e;
        }
    }

    /**
     * Check rate limits
     */
    private function checkRateLimit(): void
    {
        $usageKey = $this->cachePrefix . 'usage_' . now()->format('Y-m-d-H');
        $currentUsage = Cache::get($usageKey, 0);

        if ($currentUsage >= $this->rateLimits['requests_per_second'] * 3600) {
            throw new Exception('SERP API rate limit exceeded for this hour');
        }

        // Check monthly limits
        $monthlyUsageKey = $this->cachePrefix . 'monthly_usage_' . now()->format('Y-m');
        $monthlyUsage = Cache::get($monthlyUsageKey, 0);

        if ($monthlyUsage >= $this->rateLimits['requests_per_month']) {
            throw new Exception('SERP API monthly rate limit exceeded');
        }
    }

    /**
     * Track API usage
     */
    private function trackApiUsage(): void
    {
        // Hourly tracking
        $usageKey = $this->cachePrefix . 'usage_' . now()->format('Y-m-d-H');
        $currentUsage = Cache::get($usageKey, 0);
        Cache::put($usageKey, $currentUsage + 1, 3600);

        // Monthly tracking
        $monthlyUsageKey = $this->cachePrefix . 'monthly_usage_' . now()->format('Y-m');
        $monthlyUsage = Cache::get($monthlyUsageKey, 0);
        $monthlyTtl = now()->endOfMonth()->diffInSeconds(now());
        Cache::put($monthlyUsageKey, $monthlyUsage + 1, $monthlyTtl);
    }

    /**
     * Normalize domain for comparison
     */
    private function normalizeDomain(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $domain = parse_url($url, PHP_URL_HOST);
        if (!$domain) {
            return '';
        }

        // Remove www prefix
        return str_replace('www.', '', strtolower($domain));
    }

    /**
     * Get API usage statistics
     */
    public function getUsageStats(): array
    {
        $currentHour = now()->format('Y-m-d-H');
        $currentMonth = now()->format('Y-m');

        return [
            'hourly_usage' => Cache::get($this->cachePrefix . 'usage_' . $currentHour, 0),
            'hourly_limit' => $this->rateLimits['requests_per_second'] * 3600,
            'monthly_usage' => Cache::get($this->cachePrefix . 'monthly_usage_' . $currentMonth, 0),
            'monthly_limit' => $this->rateLimits['requests_per_month'],
            'last_request' => Cache::get($this->cachePrefix . 'last_request'),
            'rate_limits' => $this->rateLimits,
        ];
    }

    /**
     * Test API connection
     */
    public function testConnection(): bool
    {
        try {
            $testParams = [
                'api_key' => $this->apiKey,
                'engine' => 'google',
                'q' => 'test search',
                'num' => 1,
            ];

            $response = Http::timeout(10)->get($this->baseUrl, $testParams);
            
            if ($response->successful()) {
                $data = $response->json();
                return !isset($data['error']);
            }

            return false;

        } catch (Exception $e) {
            Log::error('SERP API connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get available search engines
     */
    public function getAvailableEngines(): array
    {
        return [
            'google' => [
                'name' => 'Google',
                'supports' => ['organic', 'images', 'news', 'shopping', 'local'],
            ],
            'bing' => [
                'name' => 'Bing',
                'supports' => ['organic', 'images', 'news'],
            ],
            'yahoo' => [
                'name' => 'Yahoo',
                'supports' => ['organic'],
            ],
            'duckduckgo' => [
                'name' => 'DuckDuckGo',
                'supports' => ['organic'],
            ],
            'yandex' => [
                'name' => 'Yandex',
                'supports' => ['organic', 'images'],
            ],
        ];
    }

    /**
     * Get supported locations
     */
    public function getSupportedLocations(): array
    {
        // This would typically be fetched from SERP API
        // For now, return common locations
        return [
            'United States',
            'United Kingdom', 
            'Canada',
            'Australia',
            'Germany',
            'France',
            'Spain',
            'Italy',
            'Netherlands',
            'Brazil',
            'India',
            'Japan',
            'South Korea',
            'Singapore',
            'Mexico',
        ];
    }

    /**
     * Clear cache for specific parameters
     */
    public function clearCache(string $query = null, array $params = []): bool
    {
        try {
            if ($query) {
                $searchParams = array_merge(['q' => $query], $params);
                $cacheKey = $this->cachePrefix . 'google_' . md5(serialize($searchParams));
                Cache::forget($cacheKey);
            } else {
                // Clear all SERP API cache
                // This would need a more sophisticated cache tagging system
                Log::info('Would clear all SERP API cache');
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to clear SERP API cache', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}