<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Keyword;
use App\Models\Project;
use Illuminate\Support\Collection;

final class SEOCalculationService
{
    /**
     * Calculate overall SEO visibility score for a project
     */
    public function calculateVisibilityScore(Project $project): float
    {
        $keywords = $project->keywords()->where('is_active', true)->get();

        if ($keywords->isEmpty()) {
            return 0.0;
        }

        $totalVisibility = 0;
        $totalSearchVolume = 0;

        foreach ($keywords as $keyword) {
            $position = $keyword->latest_position;
            $searchVolume = $keyword->search_volume ?? 100; // Default volume if not available

            if ($position) {
                // Calculate CTR based on position (industry averages)
                $ctr = $this->getEstimatedCtr($position);

                // Calculate visibility (search volume * CTR / 100)
                $visibility = ($searchVolume * $ctr) / 100;
                $totalVisibility += $visibility;
            }

            $totalSearchVolume += $searchVolume;
        }

        // Return visibility as percentage of potential
        return $totalSearchVolume > 0 ? round(($totalVisibility / $totalSearchVolume) * 100, 2) : 0.0;
    }

    /**
     * Calculate estimated organic traffic potential
     */
    public function calculateTrafficPotential(Project $project): array
    {
        $keywords = $project->keywords()->where('is_active', true)->get();

        $potential = [
            'current_estimated_traffic' => 0,
            'top_10_potential' => 0,
            'top_3_potential' => 0,
            'position_1_potential' => 0,
            'improvement_opportunities' => [],
        ];

        foreach ($keywords as $keyword) {
            $position = $keyword->latest_position;
            $searchVolume = $keyword->search_volume ?? 100;

            if ($position) {
                $currentCtr = $this->getEstimatedCtr($position);
                $currentTraffic = ($searchVolume * $currentCtr) / 100;
                $potential['current_estimated_traffic'] += $currentTraffic;

                // Calculate potential for different positions
                $top10Traffic = ($searchVolume * $this->getEstimatedCtr(10)) / 100;
                $top3Traffic = ($searchVolume * $this->getEstimatedCtr(3)) / 100;
                $position1Traffic = ($searchVolume * $this->getEstimatedCtr(1)) / 100;

                $potential['top_10_potential'] += $top10Traffic;
                $potential['top_3_potential'] += $top3Traffic;
                $potential['position_1_potential'] += $position1Traffic;

                // Identify improvement opportunities
                if ($position > 10) {
                    $improvementPotential = $top10Traffic - $currentTraffic;
                    $potential['improvement_opportunities'][] = [
                        'keyword' => $keyword->term,
                        'current_position' => $position,
                        'current_traffic' => round($currentTraffic),
                        'potential_traffic' => round($top10Traffic),
                        'improvement_potential' => round($improvementPotential),
                        'search_volume' => $searchVolume,
                    ];
                }
            }
        }

        // Sort improvement opportunities by potential
        usort($potential['improvement_opportunities'],
            fn (array $a, array $b): int => $b['improvement_potential'] <=> $a['improvement_potential']
        );

        // Round final numbers
        $potential['current_estimated_traffic'] = round($potential['current_estimated_traffic']);
        $potential['top_10_potential'] = round($potential['top_10_potential']);
        $potential['top_3_potential'] = round($potential['top_3_potential']);
        $potential['position_1_potential'] = round($potential['position_1_potential']);

        return $potential;
    }

    /**
     * Calculate keyword difficulty score
     */
    public function calculateKeywordDifficulty(string $keyword): float
    {
        // This is a simplified difficulty calculation
        // In a real implementation, you'd analyze:
        // - Domain authority of ranking pages
        // - Content quality and length
        // - Backlink profiles
        // - SERP features present

        $factors = [
            'keyword_length' => $this->analyzeKeywordLength($keyword),
            'commercial_intent' => $this->analyzeCommercialIntent($keyword),
            'competition_level' => $this->analyzeCompetitionLevel(),
        ];

        // Weighted average of factors
        $difficulty = ($factors['keyword_length'] * 0.3) +
                     ($factors['commercial_intent'] * 0.4) +
                     ($factors['competition_level'] * 0.3);

        return round($difficulty, 1);
    }

    /**
     * Calculate ROI for SEO efforts
     */
    public function calculateSeoRoi(Project $project, array $options = []): array
    {
        $monthsBack = $options['months'] ?? 12;
        $avgOrderValue = $options['avg_order_value'] ?? $project->avg_order_value ?? 100;
        $conversionRate = $options['conversion_rate'] ?? $project->conversion_rate ?? 0.02;

        // Get traffic growth data
        $trafficData = $this->getTrafficGrowthData($project, $monthsBack);

        $roi = [
            'organic_traffic_growth' => $trafficData['growth_percentage'],
            'estimated_revenue' => 0,
            'estimated_conversions' => 0,
            'traffic_value' => 0,
            'roi_percentage' => 0,
        ];

        if ($trafficData['current_traffic'] > 0) {
            $estimatedConversions = $trafficData['current_traffic'] * $conversionRate;
            $estimatedRevenue = $estimatedConversions * $avgOrderValue;

            // Estimate cost of paid traffic equivalent
            $avgCpc = $options['avg_cpc'] ?? 2.50; // Default CPC
            $trafficValue = $trafficData['current_traffic'] * $avgCpc;

            // Calculate ROI (simplified - assumes SEO cost vs paid traffic value)
            $seoInvestment = $options['seo_investment'] ?? ($trafficValue * 0.3); // Assume 30% of paid equivalent
            $roiPercentage = $seoInvestment > 0 ? (($trafficValue - $seoInvestment) / $seoInvestment) * 100 : 0;

            $roi = [
                'organic_traffic_growth' => $trafficData['growth_percentage'],
                'current_monthly_traffic' => round($trafficData['current_traffic']),
                'estimated_revenue' => round($estimatedRevenue, 2),
                'estimated_conversions' => round($estimatedConversions),
                'traffic_value' => round($trafficValue, 2),
                'seo_investment' => round($seoInvestment, 2),
                'roi_percentage' => round($roiPercentage, 2),
            ];
        }

        return $roi;
    }

    /**
     * Generate SEO recommendations based on project data
     */
    public function generateRecommendations(Project $project, array $reportData = []): array
    {
        $recommendations = [];
        $summary = $reportData['summary'] ?? [];

        // Position-based recommendations
        if (($summary['average_position'] ?? 0) > 20) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Content Optimization',
                'title' => 'Improve Content Quality and Relevance',
                'description' => 'Average position above 20 indicates content may not be meeting search intent. Focus on comprehensive content that directly addresses user queries.',
                'action_items' => [
                    'Conduct keyword intent analysis for top performing keywords',
                    'Audit existing content for comprehensiveness and relevance',
                    'Create detailed content briefs for underperforming pages',
                    'Implement content optimization based on top-ranking competitor analysis',
                ],
            ];
        }

        // CTR-based recommendations
        if (($summary['click_through_rate'] ?? 0) < 2) {
            $recommendations[] = [
                'priority' => 'medium',
                'category' => 'Title and Meta Optimization',
                'title' => 'Optimize Title Tags and Meta Descriptions',
                'description' => 'Low click-through rate suggests titles and meta descriptions are not compelling enough to drive clicks.',
                'action_items' => [
                    'Audit title tags for keyword placement and compelling copy',
                    'Rewrite meta descriptions with clear value propositions',
                    'A/B test different title and meta variations',
                    'Include power words and emotional triggers in titles',
                ],
            ];
        }

        // Keyword coverage recommendations
        $keywordCoverage = $summary['ranking_keywords'] / max($summary['total_keywords'], 1) * 100;
        if ($keywordCoverage < 50) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Keyword Strategy',
                'title' => 'Expand Keyword Coverage',
                'description' => 'Only '.round($keywordCoverage, 1).'% of tracked keywords are ranking. Expand content strategy to target more keywords.',
                'action_items' => [
                    'Identify content gaps for non-ranking keywords',
                    'Create topic clusters around primary keywords',
                    'Develop supporting content for long-tail variations',
                    'Optimize existing pages for multiple related keywords',
                ],
            ];
        }

        // Featured snippet opportunities
        if (($summary['featured_snippets'] ?? 0) === 0) {
            $topKeywords = $this->getTop10Keywords($project);
            if ($topKeywords->count() > 5) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'category' => 'SERP Features',
                    'title' => 'Target Featured Snippet Opportunities',
                    'description' => 'With strong rankings in top 10, focus on securing featured snippets for increased visibility.',
                    'action_items' => [
                        'Identify featured snippet opportunities for top keywords',
                        'Format content with clear headings and concise answers',
                        'Use structured data markup where appropriate',
                        'Create FAQ sections for question-based keywords',
                    ],
                ];
            }
        }

        // Technical SEO recommendations
        $recommendations[] = $this->generateTechnicalSeoRecommendations();

        // Competitor-based recommendations
        if (! empty($reportData['competitors'])) {
            $competitorRecommendations = $this->generateCompetitorRecommendations($reportData['competitors']);
            $recommendations = array_merge($recommendations, $competitorRecommendations);
        }

        // Sort by priority
        usort($recommendations, function (array $a, array $b): int {
            $priority = ['high' => 3, 'medium' => 2, 'low' => 1];

            return ($priority[$b['priority']] ?? 0) <=> ($priority[$a['priority']] ?? 0);
        });

        return array_filter($recommendations); // Remove any null entries
    }

    /**
     * Calculate keyword performance trends
     */
    public function calculateKeywordTrends(Collection $keywords, int $days = 30): array
    {
        $trends = [
            'improving' => 0,
            'declining' => 0,
            'stable' => 0,
            'new_rankings' => 0,
            'lost_rankings' => 0,
        ];

        foreach ($keywords as $keyword) {
            $positionHistory = $keyword->positions()
                ->where('tracked_at', '>=', now()->subDays($days))
                ->orderBy('tracked_at', 'desc')
                ->limit(10)
                ->get();

            if ($positionHistory->count() < 2) {
                continue;
            }

            $latest = $positionHistory->first();
            $previous = $positionHistory->skip(1)->first();

            if (! $previous->position && $latest->position) {
                $trends['new_rankings']++;
            } elseif ($previous->position && ! $latest->position) {
                $trends['lost_rankings']++;
            } elseif ($latest->position && $previous->position) {
                $change = $previous->position - $latest->position;

                if ($change > 2) {
                    $trends['improving']++;
                } elseif ($change < -2) {
                    $trends['declining']++;
                } else {
                    $trends['stable']++;
                }
            }
        }

        return $trends;
    }

    /**
     * Calculate search volume trends for keywords
     */
    public function calculateSearchVolumeTrends(Collection $keywords): array
    {
        $volumeData = [
            'total_volume' => 0,
            'high_volume_keywords' => 0,
            'medium_volume_keywords' => 0,
            'low_volume_keywords' => 0,
            'volume_distribution' => [],
        ];

        foreach ($keywords as $keyword) {
            $volume = $keyword->search_volume ?? 0;
            $volumeData['total_volume'] += $volume;

            if ($volume >= 1000) {
                $volumeData['high_volume_keywords']++;
            } elseif ($volume >= 100) {
                $volumeData['medium_volume_keywords']++;
            } else {
                $volumeData['low_volume_keywords']++;
            }

            // Group by volume ranges for distribution
            $range = match (true) {
                $volume >= 10000 => '10k+',
                $volume >= 1000 => '1k-10k',
                $volume >= 100 => '100-1k',
                $volume >= 10 => '10-100',
                default => '0-10',
            };

            if (! isset($volumeData['volume_distribution'][$range])) {
                $volumeData['volume_distribution'][$range] = 0;
            }

            $volumeData['volume_distribution'][$range]++;
        }

        return $volumeData;
    }

    /**
     * Calculate content optimization opportunities
     */
    public function calculateContentOpportunities(Project $project): array
    {
        $opportunities = [];

        // Find keywords ranking 11-20 (opportunity to get to first page)
        $page2Keywords = $project->keywords()
            ->where('is_active', true)
            ->whereBetween('latest_position', [11, 20])
            ->orderBy('search_volume', 'desc')
            ->limit(10)
            ->get();

        foreach ($page2Keywords as $keyword) {
            $trafficPotential = ($keyword->search_volume ?? 100) * ($this->getEstimatedCtr(5) / 100);

            $opportunities[] = [
                'keyword' => $keyword->term,
                'current_position' => $keyword->latest_position,
                'search_volume' => $keyword->search_volume,
                'traffic_potential' => round($trafficPotential),
                'opportunity_type' => 'first_page',
                'estimated_effort' => 'medium',
            ];
        }

        // Find high-volume keywords not ranking
        $nonRankingKeywords = $project->keywords()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('latest_position')
                    ->orWhere('latest_position', '>', 100);
            })
            ->orderBy('search_volume', 'desc')
            ->limit(5)
            ->get();

        foreach ($nonRankingKeywords as $keyword) {
            $trafficPotential = ($keyword->search_volume ?? 100) * ($this->getEstimatedCtr(15) / 100);

            $opportunities[] = [
                'keyword' => $keyword->term,
                'current_position' => null,
                'search_volume' => $keyword->search_volume,
                'traffic_potential' => round($trafficPotential),
                'opportunity_type' => 'new_content',
                'estimated_effort' => 'high',
            ];
        }

        // Sort by traffic potential
        usort($opportunities, fn (array $a, array $b): int => $b['traffic_potential'] <=> $a['traffic_potential']);

        return $opportunities;
    }

    /**
     * Calculate SERP feature opportunities
     */
    public function calculateSerpFeatureOpportunities(Project $project): array
    {
        $opportunities = [];

        // Find keywords with featured snippet potential (ranking 1-5)
        $topKeywords = $this->getTop10Keywords($project);

        foreach ($topKeywords as $keyword) {
            $hasFeaturedSnippet = $keyword->positions()
                ->whereHas('serpFeatures', function ($query): void {
                    $query->where('feature_type', 'featured_snippet');
                })
                ->exists();

            if (! $hasFeaturedSnippet && $keyword->latest_position <= 5) {
                $opportunities[] = [
                    'keyword' => $keyword->term,
                    'feature_type' => 'featured_snippet',
                    'current_position' => $keyword->latest_position,
                    'opportunity_score' => $this->calculateFeatureOpportunityScore($keyword),
                ];
            }
        }

        return $opportunities;
    }

    /**
     * Estimate CTR based on position (industry averages)
     */
    private function getEstimatedCtr(int $position): float
    {
        return match (true) {
            $position === 1 => 31.7,
            $position === 2 => 24.7,
            $position === 3 => 18.7,
            $position === 4 => 13.1,
            $position === 5 => 9.2,
            $position === 6 => 6.8,
            $position === 7 => 4.8,
            $position === 8 => 3.5,
            $position === 9 => 2.8,
            $position === 10 => 2.5,
            $position <= 20 => 1.5,
            $position <= 30 => 1.0,
            $position <= 50 => 0.5,
            $position <= 100 => 0.2,
            default => 0.1,
        };
    }

    /**
     * Analyze keyword length factor
     */
    private function analyzeKeywordLength(string $keyword): float
    {
        $wordCount = str_word_count($keyword);

        return match (true) {
            $wordCount === 1 => 90, // Very difficult
            $wordCount === 2 => 70, // Difficult
            $wordCount === 3 => 50, // Moderate
            $wordCount >= 4 => 30,  // Easy
            default => 50,
        };
    }

    /**
     * Analyze commercial intent factor
     */
    private function analyzeCommercialIntent(string $keyword): float
    {
        $commercialKeywords = ['buy', 'purchase', 'price', 'cost', 'cheap', 'sale', 'discount', 'deal'];
        $informationalKeywords = ['how', 'what', 'why', 'guide', 'tutorial', 'tips'];

        $keyword = mb_strtolower($keyword);

        foreach ($commercialKeywords as $term) {
            if (mb_strpos($keyword, $term) !== false) {
                return 80; // High competition for commercial terms
            }
        }

        foreach ($informationalKeywords as $term) {
            if (mb_strpos($keyword, $term) !== false) {
                return 40; // Lower competition for informational terms
            }
        }

        return 60; // Default moderate competition
    }

    /**
     * Analyze competition level (simplified)
     */
    private function analyzeCompetitionLevel(): float
    {
        // This would typically involve analyzing:
        // - Number of search results
        // - Domain authority of ranking pages
        // - Content quality metrics
        // For now, return a base score
        return 50;
    }

    /**
     * Get traffic growth data for a project
     */
    private function getTrafficGrowthData(Project $project, int $months): array
    {
        // This would typically integrate with Analytics data
        // For now, we'll estimate based on keyword positions

        $keywords = $project->keywords()->where('is_active', true)->get();

        $currentTraffic = 0;
        $pastTraffic = 0;

        foreach ($keywords as $keyword) {
            $searchVolume = $keyword->search_volume ?? 100;
            $currentPosition = $keyword->latest_position;

            if ($currentPosition) {
                $currentCtr = $this->getEstimatedCtr($currentPosition);
                $currentTraffic += ($searchVolume * $currentCtr) / 100;
            }

            // Get position from X months ago
            $pastPosition = $keyword->positions()
                ->where('tracked_at', '<=', now()->subMonths($months))
                ->orderBy('tracked_at', 'desc')
                ->value('position');

            if ($pastPosition) {
                $pastCtr = $this->getEstimatedCtr($pastPosition);
                $pastTraffic += ($searchVolume * $pastCtr) / 100;
            }
        }

        $growthPercentage = $pastTraffic > 0
            ? round((($currentTraffic - $pastTraffic) / $pastTraffic) * 100, 2)
            : 0;

        return [
            'current_traffic' => $currentTraffic,
            'past_traffic' => $pastTraffic,
            'growth_percentage' => $growthPercentage,
        ];
    }

    /**
     * Get keywords ranking in top 10
     */
    private function getTop10Keywords(Project $project): Collection
    {
        return $project->keywords()
            ->where('is_active', true)
            ->where('latest_position', '<=', 10)
            ->where('latest_position', '>', 0)
            ->get();
    }

    /**
     * Generate technical SEO recommendations
     */
    private function generateTechnicalSeoRecommendations(): array
    {
        return [
            'priority' => 'medium',
            'category' => 'Technical SEO',
            'title' => 'Technical SEO Audit and Optimization',
            'description' => 'Ensure technical foundation is optimized for search engine crawling and indexing.',
            'action_items' => [
                'Conduct comprehensive technical SEO audit',
                'Optimize page loading speeds (Core Web Vitals)',
                'Ensure mobile-first indexing compliance',
                'Implement proper URL structure and internal linking',
                'Verify XML sitemaps and robots.txt configuration',
            ],
        ];
    }

    /**
     * Generate competitor-based recommendations
     */
    private function generateCompetitorRecommendations(array $competitors): array
    {
        $recommendations = [];

        // Find strongest competitor
        $strongestCompetitor = collect($competitors)->sortBy('average_position')->first();

        if ($strongestCompetitor && $strongestCompetitor['better_positions'] > 5) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'Competitive Analysis',
                'title' => 'Address Competitive Gaps',
                'description' => sprintf('%s outranks you on %s keywords.', $strongestCompetitor['domain'], $strongestCompetitor['better_positions']),
                'action_items' => [
                    'Analyze top-performing competitor content strategies',
                    'Identify content gaps where competitors rank better',
                    'Review competitor backlink profiles for opportunities',
                    'Benchmark content quality and comprehensiveness',
                ],
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate opportunity score for SERP features
     */
    private function calculateFeatureOpportunityScore(Keyword $keyword): int
    {
        $score = 0;

        // Base score from position
        if ($keyword->latest_position <= 3) {
            $score += 80;
        } elseif ($keyword->latest_position <= 5) {
            $score += 60;
        } elseif ($keyword->latest_position <= 10) {
            $score += 40;
        }

        // Bonus for search volume
        if (($keyword->search_volume ?? 0) >= 1000) {
            $score += 20;
        } elseif (($keyword->search_volume ?? 0) >= 100) {
            $score += 10;
        }

        return min($score, 100);
    }
}
