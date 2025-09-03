<?php

namespace App\Services;

use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Project;
use App\Models\SerpFeature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class SerpTrackingService
{
    private string $serpApiKey;
    private string $baseUrl = 'https://serpapi.com/search.json';
    
    public function __construct()
    {
        $this->serpApiKey = config('services.serp.api_key', '');
    }

    /**
     * Track positions for a single keyword
     */
    public function trackKeywordPosition(Keyword $keyword): ?KeywordPosition
    {
        try {
            $serpData = $this->fetchSerpData($keyword->term, $keyword->location);
            
            if (!$serpData) {
                Log::warning("No SERP data found for keyword: {$keyword->term}");
                return null;
            }

            $position = $this->findDomainPosition($serpData, $keyword->project->domain);
            
            $keywordPosition = KeywordPosition::create([
                'keyword_id' => $keyword->id,
                'position' => $position,
                'url' => $this->extractRankingUrl($serpData, $position),
                'search_volume' => $serpData['search_metadata']['total_results'] ?? null,
                'tracked_at' => now(),
                'serp_features' => $this->extractSerpFeatures($serpData),
                'metadata' => [
                    'total_results' => $serpData['search_metadata']['total_results'] ?? 0,
                    'search_time' => $serpData['search_metadata']['processed_at'] ?? null,
                    'location' => $keyword->location,
                    'device' => $keyword->device ?? 'desktop',
                ]
            ]);

            // Store SERP features separately
            $this->storeSerpFeatures($keywordPosition, $serpData);
            
            // Update keyword's latest position
            $keyword->update(['latest_position' => $position]);
            
            Log::info("Position tracked for keyword: {$keyword->term} at position: " . ($position ?? 'not found'));
            
            return $keywordPosition;
            
        } catch (Exception $e) {
            Log::error("Failed to track keyword position: {$keyword->term}", [
                'error' => $e->getMessage(),
                'keyword_id' => $keyword->id
            ]);
            return null;
        }
    }

    /**
     * Track positions for multiple keywords
     */
    public function trackMultipleKeywords(Collection $keywords): Collection
    {
        $results = collect();
        
        foreach ($keywords as $keyword) {
            $position = $this->trackKeywordPosition($keyword);
            if ($position) {
                $results->push($position);
            }
            
            // Rate limiting - wait between requests
            usleep(500000); // 0.5 second delay
        }
        
        return $results;
    }

    /**
     * Track positions for all keywords in a project
     */
    public function trackProjectKeywords(Project $project): Collection
    {
        $keywords = $project->keywords()
            ->where('is_active', true)
            ->get();
            
        return $this->trackMultipleKeywords($keywords);
    }

    /**
     * Fetch SERP data from external API
     */
    private function fetchSerpData(string $query, string $location = 'United States'): ?array
    {
        $cacheKey = "serp_data:" . md5($query . $location);
        
        // Check cache first (cache for 1 hour)
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = Http::timeout(30)->get($this->baseUrl, [
                'api_key' => $this->serpApiKey,
                'q' => $query,
                'location' => $location,
                'hl' => 'en',
                'gl' => 'us',
                'num' => 100, // Get top 100 results
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Cache::put($cacheKey, $data, 3600); // Cache for 1 hour
                return $data;
            }

            Log::error('SERP API request failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            
            return null;
            
        } catch (Exception $e) {
            Log::error('SERP API exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find domain position in SERP results
     */
    private function findDomainPosition(array $serpData, string $domain): ?int
    {
        $organicResults = $serpData['organic_results'] ?? [];
        
        foreach ($organicResults as $index => $result) {
            $resultDomain = parse_url($result['link'] ?? '', PHP_URL_HOST);
            $resultDomain = str_replace('www.', '', $resultDomain);
            $searchDomain = str_replace('www.', '', $domain);
            
            if ($resultDomain === $searchDomain) {
                return $index + 1; // Position is 1-indexed
            }
        }
        
        return null; // Not found in top 100
    }

    /**
     * Extract ranking URL for the domain
     */
    private function extractRankingUrl(array $serpData, ?int $position): ?string
    {
        if (!$position) {
            return null;
        }
        
        $organicResults = $serpData['organic_results'] ?? [];
        $resultIndex = $position - 1;
        
        return $organicResults[$resultIndex]['link'] ?? null;
    }

    /**
     * Extract SERP features from the results
     */
    private function extractSerpFeatures(array $serpData): array
    {
        $features = [];
        
        // Check for various SERP features
        if (isset($serpData['knowledge_graph'])) {
            $features[] = 'knowledge_graph';
        }
        
        if (isset($serpData['featured_snippet'])) {
            $features[] = 'featured_snippet';
        }
        
        if (isset($serpData['image_results'])) {
            $features[] = 'images';
        }
        
        if (isset($serpData['video_results'])) {
            $features[] = 'videos';
        }
        
        if (isset($serpData['local_results'])) {
            $features[] = 'local_pack';
        }
        
        if (isset($serpData['shopping_results'])) {
            $features[] = 'shopping';
        }
        
        if (isset($serpData['ads'])) {
            $features[] = 'ads';
        }
        
        return $features;
    }

    /**
     * Store SERP features as separate entities
     */
    private function storeSerpFeatures(KeywordPosition $keywordPosition, array $serpData): void
    {
        $features = [];
        
        // Featured Snippet
        if (isset($serpData['featured_snippet'])) {
            $features[] = [
                'keyword_position_id' => $keywordPosition->id,
                'feature_type' => 'featured_snippet',
                'content' => $serpData['featured_snippet']['snippet'] ?? null,
                'url' => $serpData['featured_snippet']['link'] ?? null,
                'title' => $serpData['featured_snippet']['title'] ?? null,
                'metadata' => $serpData['featured_snippet'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        // Knowledge Graph
        if (isset($serpData['knowledge_graph'])) {
            $features[] = [
                'keyword_position_id' => $keywordPosition->id,
                'feature_type' => 'knowledge_graph',
                'content' => $serpData['knowledge_graph']['description'] ?? null,
                'url' => $serpData['knowledge_graph']['website'] ?? null,
                'title' => $serpData['knowledge_graph']['title'] ?? null,
                'metadata' => $serpData['knowledge_graph'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        
        // Local Pack
        if (isset($serpData['local_results']['places'])) {
            foreach ($serpData['local_results']['places'] as $place) {
                $features[] = [
                    'keyword_position_id' => $keywordPosition->id,
                    'feature_type' => 'local_pack',
                    'content' => $place['title'] ?? null,
                    'url' => $place['link'] ?? null,
                    'title' => $place['title'] ?? null,
                    'metadata' => $place,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        
        if (!empty($features)) {
            SerpFeature::insert($features);
        }
    }

    /**
     * Get position history for a keyword
     */
    public function getPositionHistory(Keyword $keyword, int $days = 30): Collection
    {
        return $keyword->positions()
            ->where('tracked_at', '>=', now()->subDays($days))
            ->orderBy('tracked_at', 'desc')
            ->get();
    }

    /**
     * Calculate position change trends
     */
    public function calculatePositionTrends(Keyword $keyword): array
    {
        $positions = $this->getPositionHistory($keyword, 30);
        
        if ($positions->count() < 2) {
            return [
                'trend' => 'stable',
                'change' => 0,
                'percentage_change' => 0,
            ];
        }
        
        $latest = $positions->first();
        $previous = $positions->skip(1)->first();
        
        $change = $previous->position - $latest->position; // Positive = improvement
        $trend = $change > 0 ? 'improving' : ($change < 0 ? 'declining' : 'stable');
        
        $percentageChange = $previous->position > 0 
            ? round(($change / $previous->position) * 100, 2)
            : 0;
        
        return [
            'trend' => $trend,
            'change' => $change,
            'percentage_change' => $percentageChange,
            'current_position' => $latest->position,
            'previous_position' => $previous->position,
        ];
    }

    /**
     * Get competitors in SERP for a keyword
     */
    public function getCompetitorsInSerp(Keyword $keyword): array
    {
        $latestPosition = $keyword->positions()->latest('tracked_at')->first();
        
        if (!$latestPosition || !$latestPosition->metadata) {
            return [];
        }
        
        $serpData = $this->fetchSerpData($keyword->term, $keyword->location);
        
        if (!$serpData) {
            return [];
        }
        
        $competitors = [];
        $organicResults = $serpData['organic_results'] ?? [];
        
        foreach ($organicResults as $index => $result) {
            $domain = parse_url($result['link'] ?? '', PHP_URL_HOST);
            $domain = str_replace('www.', '', $domain);
            
            // Skip if it's the same domain as the project
            if ($domain === str_replace('www.', '', $keyword->project->domain)) {
                continue;
            }
            
            $competitors[] = [
                'domain' => $domain,
                'position' => $index + 1,
                'title' => $result['title'] ?? null,
                'url' => $result['link'] ?? null,
                'snippet' => $result['snippet'] ?? null,
            ];
        }
        
        return array_slice($competitors, 0, 20); // Return top 20 competitors
    }
}