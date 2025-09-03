<?php

declare(strict_types=1);

use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\SEOCalculationService;

describe('SEOCalculationService', function (): void {
    let('service', fn (): SEOCalculationService => new SEOCalculationService);
    let('tenant', fn () => Tenant::factory()->create());
    let('project', fn () => Project::factory()->for($this->tenant)->create());

    describe('calculateVisibilityScore', function (): void {
        it('returns zero for projects with no keywords', function (): void {
            $score = $this->service->calculateVisibilityScore($this->project);

            expect($score)->toBe(0.0);
        });

        it('returns zero for projects with inactive keywords', function (): void {
            Keyword::factory()->count(3)->for($this->project)->for($this->tenant)->inactive()->create();

            $score = $this->service->calculateVisibilityScore($this->project);

            expect($score)->toBe(0.0);
        });

        it('calculates visibility score correctly for single keyword', function (): void {
            // Create keyword with position 1, search volume 1000
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 1,
                'search_volume' => 1000,
            ]);

            $score = $this->service->calculateVisibilityScore($this->project);

            // Position 1 has 31.7% CTR, so visibility should be 31.7%
            expect($score)->toBe(31.7);
        });

        it('calculates visibility score for multiple keywords', function (): void {
            // Keyword 1: Position 1, Volume 1000 (CTR 31.7% = 317 visibility)
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 1,
                'search_volume' => 1000,
            ]);

            // Keyword 2: Position 10, Volume 500 (CTR 2.5% = 12.5 visibility)
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 10,
                'search_volume' => 500,
            ]);

            // Total visibility: 317 + 12.5 = 329.5
            // Total volume: 1000 + 500 = 1500
            // Score: (329.5 / 1500) * 100 = 21.97%

            $score = $this->service->calculateVisibilityScore($this->project);

            expect($score)->toBe(21.97);
        });

        it('uses default search volume when not provided', function (): void {
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 1,
                'search_volume' => null,
            ]);

            $score = $this->service->calculateVisibilityScore($this->project);

            // Should use default volume of 100, CTR 31.7% = 31.7%
            expect($score)->toBe(31.7);
        });

        it('ignores keywords without positions', function (): void {
            // Active keyword with position
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 5,
                'search_volume' => 1000,
            ]);

            // Active keyword without position
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => null,
                'search_volume' => 500,
            ]);

            $score = $this->service->calculateVisibilityScore($this->project);

            // Should only calculate for positioned keyword (Position 5 = 9.2% CTR)
            expect($score)->toBe(9.2);
        });
    });

    describe('calculateTrafficPotential', function (): void {
        it('returns empty potential for projects with no keywords', function (): void {
            $potential = $this->service->calculateTrafficPotential($this->project);

            expect($potential['current_estimated_traffic'])->toBe(0);
            expect($potential['top_10_potential'])->toBe(0);
            expect($potential['top_3_potential'])->toBe(0);
            expect($potential['position_1_potential'])->toBe(0);
            expect($potential['improvement_opportunities'])->toBeEmpty();
        });

        it('calculates traffic potential correctly', function (): void {
            // Keyword with position 15, volume 1000
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 15,
                'search_volume' => 1000,
                'keyword' => 'test keyword',
            ]);

            $potential = $this->service->calculateTrafficPotential($this->project);

            // Current traffic: 1000 * (1.5/100) = 15
            // Top 10 potential: 1000 * (2.5/100) = 25
            // Top 3 potential: 1000 * (18.7/100) = 187
            // Position 1 potential: 1000 * (31.7/100) = 317

            expect($potential['current_estimated_traffic'])->toBe(15);
            expect($potential['top_10_potential'])->toBe(25);
            expect($potential['top_3_potential'])->toBe(187);
            expect($potential['position_1_potential'])->toBe(317);
        });

        it('identifies improvement opportunities', function (): void {
            // Keywords ranked beyond position 10
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 15,
                'search_volume' => 2000,
                'keyword' => 'high volume keyword',
            ]);

            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 25,
                'search_volume' => 500,
                'keyword' => 'medium volume keyword',
            ]);

            $potential = $this->service->calculateTrafficPotential($this->project);

            expect($potential['improvement_opportunities'])->toHaveCount(2);

            $firstOpportunity = $potential['improvement_opportunities'][0];
            expect($firstOpportunity['keyword'])->toBe('high volume keyword');
            expect($firstOpportunity['current_position'])->toBe(15);
            expect($firstOpportunity['search_volume'])->toBe(2000);
            expect($firstOpportunity['improvement_potential'])->toBeGreaterThan(0);
        });

        it('sorts improvement opportunities by potential', function (): void {
            // High volume keyword with lower improvement potential
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 12,
                'search_volume' => 1000,
                'keyword' => 'lower opportunity',
            ]);

            // Medium volume keyword with higher improvement potential
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 50,
                'search_volume' => 2000,
                'keyword' => 'higher opportunity',
            ]);

            $potential = $this->service->calculateTrafficPotential($this->project);

            // First opportunity should be the one with higher improvement potential
            expect($potential['improvement_opportunities'][0]['keyword'])->toBe('higher opportunity');
        });
    });

    describe('calculateKeywordDifficulty', function (): void {
        it('calculates basic difficulty correctly', function (): void {
            $difficulty = $this->service->calculateKeywordDifficulty('seo tips', 'example.com');

            expect($difficulty)->toBeFloat();
            expect($difficulty)->toBeGreaterThan(0);
            expect($difficulty)->toBeLessThanOrEqual(100);
        });

        it('assigns higher difficulty to single word keywords', function (): void {
            $singleWord = $this->service->calculateKeywordDifficulty('seo', 'example.com');
            $multiWord = $this->service->calculateKeywordDifficulty('seo optimization tips guide', 'example.com');

            expect($singleWord)->toBeGreaterThan($multiWord);
        });

        it('assigns higher difficulty to commercial keywords', function (): void {
            $commercial = $this->service->calculateKeywordDifficulty('buy seo software', 'example.com');
            $informational = $this->service->calculateKeywordDifficulty('how to do seo', 'example.com');

            expect($commercial)->toBeGreaterThan($informational);
        });

        it('handles edge cases gracefully', function (): void {
            $emptyKeyword = $this->service->calculateKeywordDifficulty('', 'example.com');
            $specialChars = $this->service->calculateKeywordDifficulty('seo-tips@2024!', 'example.com');

            expect($emptyKeyword)->toBeFloat();
            expect($specialChars)->toBeFloat();
        });
    });

    describe('calculateSeoRoi', function (): void {
        it('returns zero ROI for projects without traffic data', function (): void {
            $roi = $this->service->calculateSeoRoi($this->project);

            expect($roi['organic_traffic_growth'])->toBe(0.0);
            expect($roi['estimated_revenue'])->toBe(0);
            expect($roi['roi_percentage'])->toBe(0);
        });

        it('calculates ROI with current traffic', function (): void {
            // Create keywords with current positions
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 3,
                'search_volume' => 1000,
            ]);

            // Mock the traffic calculation by providing options
            $options = [
                'avg_order_value' => 200,
                'conversion_rate' => 0.05, // 5%
                'avg_cpc' => 3.0,
                'seo_investment' => 5000,
            ];

            $roi = $this->service->calculateSeoRoi($this->project, $options);

            expect($roi)->toHaveKeys([
                'organic_traffic_growth',
                'current_monthly_traffic',
                'estimated_revenue',
                'estimated_conversions',
                'traffic_value',
                'seo_investment',
                'roi_percentage',
            ]);

            expect($roi['estimated_revenue'])->toBeGreaterThan(0);
            expect($roi['traffic_value'])->toBeGreaterThan(0);
        });
    });

    describe('generateRecommendations', function (): void {
        it('generates content optimization recommendations for low positions', function (): void {
            $reportData = ['summary' => ['average_position' => 25]];

            $recommendations = $this->service->generateRecommendations($this->project, $reportData);

            $contentRec = collect($recommendations)->firstWhere('category', 'Content Optimization');
            expect($contentRec)->not->toBeNull();
            expect($contentRec['priority'])->toBe('high');
            expect($contentRec['action_items'])->toBeArray();
        });

        it('generates CTR optimization recommendations for low CTR', function (): void {
            $reportData = ['summary' => ['click_through_rate' => 1.5]];

            $recommendations = $this->service->generateRecommendations($this->project, $reportData);

            $ctrRec = collect($recommendations)->firstWhere('category', 'Title and Meta Optimization');
            expect($ctrRec)->not->toBeNull();
            expect($ctrRec['priority'])->toBe('medium');
        });

        it('generates keyword coverage recommendations', function (): void {
            $reportData = ['summary' => [
                'ranking_keywords' => 10,
                'total_keywords' => 30,
            ]];

            $recommendations = $this->service->generateRecommendations($this->project, $reportData);

            $keywordRec = collect($recommendations)->firstWhere('category', 'Keyword Strategy');
            expect($keywordRec)->not->toBeNull();
            expect($keywordRec['priority'])->toBe('high');
        });

        it('always includes technical SEO recommendations', function (): void {
            $recommendations = $this->service->generateRecommendations($this->project);

            $techRec = collect($recommendations)->firstWhere('category', 'Technical SEO');
            expect($techRec)->not->toBeNull();
            expect($techRec['action_items'])->toHaveCount(5);
        });

        it('sorts recommendations by priority', function (): void {
            $reportData = ['summary' => [
                'average_position' => 25, // High priority
                'click_through_rate' => 1.5, // Medium priority
                'ranking_keywords' => 10,
                'total_keywords' => 30, // High priority
            ]];

            $recommendations = $this->service->generateRecommendations($this->project, $reportData);

            // First few should be high priority
            expect($recommendations[0]['priority'])->toBe('high');
            expect($recommendations[1]['priority'])->toBeIn(['high', 'medium']);
        });
    });

    describe('calculateKeywordTrends', function (): void {
        it('identifies improving keywords correctly', function (): void {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Create position history showing improvement
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 15,
                'date' => now()->subDays(10)->format('Y-m-d'),
            ]);
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 8,
                'date' => now()->subDays(5)->format('Y-m-d'),
            ]);

            $trends = $this->service->calculateKeywordTrends(collect([$keyword]));

            expect($trends['improving'])->toBe(1);
            expect($trends['declining'])->toBe(0);
            expect($trends['stable'])->toBe(0);
        });

        it('identifies declining keywords correctly', function (): void {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Create position history showing decline
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 5,
                'date' => now()->subDays(10)->format('Y-m-d'),
            ]);
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 18,
                'date' => now()->subDays(5)->format('Y-m-d'),
            ]);

            $trends = $this->service->calculateKeywordTrends(collect([$keyword]));

            expect($trends['declining'])->toBe(1);
            expect($trends['improving'])->toBe(0);
        });

        it('identifies stable keywords correctly', function (): void {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // Create position history showing stability (change <= 2)
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 10,
                'date' => now()->subDays(10)->format('Y-m-d'),
            ]);
            KeywordPosition::factory()->for($keyword)->for($this->tenant)->create([
                'position' => 12,
                'date' => now()->subDays(5)->format('Y-m-d'),
            ]);

            $trends = $this->service->calculateKeywordTrends(collect([$keyword]));

            expect($trends['stable'])->toBe(1);
            expect($trends['improving'])->toBe(0);
            expect($trends['declining'])->toBe(0);
        });

        it('identifies new and lost rankings', function (): void {
            $newKeyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();
            $lostKeyword = Keyword::factory()->for($this->project)->for($this->tenant)->create();

            // New ranking: no previous position, current position exists
            KeywordPosition::factory()->for($newKeyword)->for($this->tenant)->create([
                'position' => null,
                'date' => now()->subDays(10)->format('Y-m-d'),
            ]);
            KeywordPosition::factory()->for($newKeyword)->for($this->tenant)->create([
                'position' => 15,
                'date' => now()->subDays(5)->format('Y-m-d'),
            ]);

            // Lost ranking: previous position exists, current position null
            KeywordPosition::factory()->for($lostKeyword)->for($this->tenant)->create([
                'position' => 12,
                'date' => now()->subDays(10)->format('Y-m-d'),
            ]);
            KeywordPosition::factory()->for($lostKeyword)->for($this->tenant)->create([
                'position' => null,
                'date' => now()->subDays(5)->format('Y-m-d'),
            ]);

            $trends = $this->service->calculateKeywordTrends(collect([$newKeyword, $lostKeyword]));

            expect($trends['new_rankings'])->toBe(1);
            expect($trends['lost_rankings'])->toBe(1);
        });
    });

    describe('calculateSearchVolumeTrends', function (): void {
        it('categorizes keywords by search volume correctly', function (): void {
            $keywords = collect([
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 15000]), // High
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 500]),   // Medium
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 50]),    // Low
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => null]),  // Low (null)
            ]);

            $volumeData = $this->service->calculateSearchVolumeTrends($keywords);

            expect($volumeData['high_volume_keywords'])->toBe(1);
            expect($volumeData['medium_volume_keywords'])->toBe(1);
            expect($volumeData['low_volume_keywords'])->toBe(2);
            expect($volumeData['total_volume'])->toBe(15550);
        });

        it('creates volume distribution correctly', function (): void {
            $keywords = collect([
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 50000]), // 10k+
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 5000]),  // 1k-10k
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 500]),   // 100-1k
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 50]),    // 10-100
                Keyword::factory()->for($this->project)->for($this->tenant)->make(['search_volume' => 5]),     // 0-10
            ]);

            $volumeData = $this->service->calculateSearchVolumeTrends($keywords);

            expect($volumeData['volume_distribution']['10k+'])->toBe(1);
            expect($volumeData['volume_distribution']['1k-10k'])->toBe(1);
            expect($volumeData['volume_distribution']['100-1k'])->toBe(1);
            expect($volumeData['volume_distribution']['10-100'])->toBe(1);
            expect($volumeData['volume_distribution']['0-10'])->toBe(1);
        });
    });

    describe('calculateContentOpportunities', function (): void {
        it('identifies first page opportunities', function (): void {
            // Keywords ranking on page 2 (positions 11-20)
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 15,
                'search_volume' => 2000,
                'keyword' => 'first page opportunity',
            ]);

            $opportunities = $this->service->calculateContentOpportunities($this->project);

            expect($opportunities)->not->toBeEmpty();
            expect($opportunities[0]['opportunity_type'])->toBe('first_page');
            expect($opportunities[0]['current_position'])->toBe(15);
            expect($opportunities[0]['estimated_effort'])->toBe('medium');
        });

        it('identifies new content opportunities', function (): void {
            // High volume keywords not ranking
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => null,
                'search_volume' => 5000,
                'keyword' => 'new content opportunity',
            ]);

            $opportunities = $this->service->calculateContentOpportunities($this->project);

            $newContentOpp = collect($opportunities)->firstWhere('opportunity_type', 'new_content');
            expect($newContentOpp)->not->toBeNull();
            expect($newContentOpp['current_position'])->toBeNull();
            expect($newContentOpp['estimated_effort'])->toBe('high');
        });

        it('sorts opportunities by traffic potential', function (): void {
            // Lower volume keyword
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 15,
                'search_volume' => 500,
                'keyword' => 'lower potential',
            ]);

            // Higher volume keyword
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 15,
                'search_volume' => 5000,
                'keyword' => 'higher potential',
            ]);

            $opportunities = $this->service->calculateContentOpportunities($this->project);

            expect($opportunities[0]['keyword'])->toBe('higher potential');
        });
    });

    describe('Edge Cases and Error Handling', function (): void {
        it('handles empty collections gracefully', function (): void {
            $trends = $this->service->calculateKeywordTrends(collect([]));
            $volumeData = $this->service->calculateSearchVolumeTrends(collect([]));

            expect($trends['improving'])->toBe(0);
            expect($volumeData['total_volume'])->toBe(0);
        });

        it('handles null search volumes gracefully', function (): void {
            $keyword = Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 5,
                'search_volume' => null,
            ]);

            $score = $this->service->calculateVisibilityScore($this->project);

            // Should use default volume and calculate correctly
            expect($score)->toBeFloat();
            expect($score)->toBeGreaterThan(0);
        });

        it('handles extreme position values', function (): void {
            Keyword::factory()->for($this->project)->for($this->tenant)->create([
                'is_tracking_active' => true,
                'current_position' => 999,
                'search_volume' => 1000,
            ]);

            $score = $this->service->calculateVisibilityScore($this->project);

            expect($score)->toBe(0.1); // Should use default CTR for extreme positions
        });
    });
});
