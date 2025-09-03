<?php

use App\Models\Keyword;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\KeywordPosition;
use App\Models\SerpFeature;
use App\Models\Notification;

describe('Keyword Model', function () {
    
    describe('Factory and Creation', function () {
        it('can be created with valid data', function () {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $keyword = Keyword::factory()->for($project)->for($tenant)->create();

            expect($keyword)->toBeInstanceOf(Keyword::class);
            expect($keyword->tenant_id)->toBe($tenant->id);
            expect($keyword->project_id)->toBe($project->id);
            expect($keyword->keyword)->not->toBeEmpty();
            expect($keyword->keyword_hash)->not->toBeEmpty();
        });

        it('generates keyword hash automatically', function () {
            $keywordText = 'SEO Best Practices';
            $keyword = Keyword::factory()->create([
                'keyword' => $keywordText,
                'keyword_hash' => null
            ]);

            expect($keyword->keyword_hash)->toBe(md5(strtolower(trim($keywordText))));
        });

        it('can create high priority keywords', function () {
            $keyword = Keyword::factory()->highPriority()->create();

            expect($keyword->priority)->toBe('high');
            expect($keyword->search_volume)->toBeGreaterThanOrEqual(1000);
        });

        it('can create top ranking keywords', function () {
            $topKeyword = Keyword::factory()->topThree()->create();
            $top10Keyword = Keyword::factory()->topTen()->create();

            expect($topKeyword->current_position)->toBeWithinRange(1, 3);
            expect($top10Keyword->current_position)->toBeWithinRange(1, 10);
        });

        it('can create improving and declining keywords', function () {
            $improvingKeyword = Keyword::factory()->improving()->create();
            $decliningKeyword = Keyword::factory()->declining()->create();

            expect($improvingKeyword->current_position)->toBeLessThan($improvingKeyword->previous_position);
            expect($decliningKeyword->current_position)->toBeGreaterThan($decliningKeyword->previous_position);
        });
    });

    describe('Relationships', function () {
        it('belongs to a project', function () {
            $project = Project::factory()->create();
            $keyword = Keyword::factory()->for($project)->for($project->tenant)->create();

            expect($keyword->project)->toBeInstanceOf(Project::class);
            expect($keyword->project->id)->toBe($project->id);
        });

        it('has many positions', function () {
            $keyword = Keyword::factory()->create();
            $positions = KeywordPosition::factory()->count(5)->for($keyword)->for($keyword->tenant)->create();

            expect($keyword->positions)->toHaveCount(5);
            expect($keyword->positions->first())->toBeInstanceOf(KeywordPosition::class);
        });

        it('has many SERP features', function () {
            $keyword = Keyword::factory()->create();
            $serpFeatures = SerpFeature::factory()->count(3)->for($keyword)->for($keyword->tenant)->create();

            expect($keyword->serpFeatures)->toHaveCount(3);
            expect($keyword->serpFeatures->first())->toBeInstanceOf(SerpFeature::class);
        });

        it('has many notifications', function () {
            $keyword = Keyword::factory()->create();
            $notifications = Notification::factory()->count(2)->for($keyword)->for($keyword->tenant)->create();

            expect($keyword->notifications)->toHaveCount(2);
            expect($keyword->notifications->first())->toBeInstanceOf(Notification::class);
        });
    });

    describe('Scopes', function () {
        it('scopes active keywords', function () {
            $activeKeyword = Keyword::factory()->create(['is_tracking_active' => true]);
            $inactiveKeyword = Keyword::factory()->inactive()->create();

            $activeKeywords = Keyword::active()->get();

            expect($activeKeywords)->toContain($activeKeyword);
            expect($activeKeywords)->not->toContain($inactiveKeyword);
        });

        it('scopes by priority', function () {
            $highKeyword = Keyword::factory()->create(['priority' => 'high']);
            $mediumKeyword = Keyword::factory()->create(['priority' => 'medium']);

            $highPriorityKeywords = Keyword::byPriority('high')->get();

            expect($highPriorityKeywords)->toContain($highKeyword);
            expect($highPriorityKeywords)->not->toContain($mediumKeyword);
        });

        it('scopes by intent', function () {
            $commercialKeyword = Keyword::factory()->withIntent('commercial')->create();
            $informationalKeyword = Keyword::factory()->withIntent('informational')->create();

            $commercialKeywords = Keyword::byIntent('commercial')->get();

            expect($commercialKeywords)->toContain($commercialKeyword);
            expect($commercialKeywords)->not->toContain($informationalKeyword);
        });

        it('scopes top 10 and top 3', function () {
            $topKeyword = Keyword::factory()->create(['current_position' => 2]);
            $midKeyword = Keyword::factory()->create(['current_position' => 15]);

            $top10Keywords = Keyword::inTop10()->get();
            $top3Keywords = Keyword::inTop3()->get();

            expect($top10Keywords)->toContain($topKeyword);
            expect($top10Keywords)->not->toContain($midKeyword);
            expect($top3Keywords)->toContain($topKeyword);
        });

        it('scopes by country', function () {
            $usKeyword = Keyword::factory()->create(['country' => 'US']);
            $ukKeyword = Keyword::factory()->create(['country' => 'GB']);

            $usKeywords = Keyword::byCountry('US')->get();

            expect($usKeywords)->toContain($usKeyword);
            expect($usKeywords)->not->toContain($ukKeyword);
        });

        it('scopes with categories', function () {
            $brandKeyword = Keyword::factory()->branded()->create();
            $productKeyword = Keyword::factory()->create(['categories' => ['product', 'commercial']]);

            $brandKeywords = Keyword::withCategories(['brand'])->get();
            $productKeywords = Keyword::withCategories(['product'])->get();

            expect($brandKeywords)->toContain($brandKeyword);
            expect($productKeywords)->toContain($productKeyword);
            expect($productKeywords)->not->toContain($brandKeyword);
        });
    });

    describe('Analytics Methods', function () {
        it('gets latest position', function () {
            $keyword = Keyword::factory()->create();
            $oldPosition = KeywordPosition::factory()->for($keyword)->for($keyword->tenant)->create(['date' => '2024-01-01']);
            $latestPosition = KeywordPosition::factory()->for($keyword)->for($keyword->tenant)->create(['date' => '2024-01-15']);

            expect($keyword->getLatestPosition()->id)->toBe($latestPosition->id);
        });

        it('gets position history', function () {
            $keyword = Keyword::factory()->create();
            
            // Create positions over 45 days
            KeywordPosition::factory()->for($keyword)->for($keyword->tenant)->create(['date' => now()->subDays(45)]);
            $recent1 = KeywordPosition::factory()->for($keyword)->for($keyword->tenant)->create(['date' => now()->subDays(15)]);
            $recent2 = KeywordPosition::factory()->for($keyword)->for($keyword->tenant)->create(['date' => now()->subDays(5)]);

            $history = $keyword->getPositionHistory(30);

            expect($history)->toHaveCount(2);
            expect($history->pluck('id'))->toContain($recent1->id, $recent2->id);
        });

        it('calculates position change correctly', function () {
            $improvingKeyword = Keyword::factory()->create([
                'current_position' => 5,
                'previous_position' => 10
            ]);

            $decliningKeyword = Keyword::factory()->create([
                'current_position' => 15,
                'previous_position' => 8
            ]);

            $noChangeKeyword = Keyword::factory()->create([
                'current_position' => 5,
                'previous_position' => 5
            ]);

            expect($improvingKeyword->getPositionChange())->toBe(5); // Improved by 5 positions
            expect($decliningKeyword->getPositionChange())->toBe(-7); // Declined by 7 positions
            expect($noChangeKeyword->getPositionChange())->toBe(0);
        });

        it('identifies improving and declining keywords', function () {
            $improvingKeyword = Keyword::factory()->improving()->create();
            $decliningKeyword = Keyword::factory()->declining()->create();
            $stableKeyword = Keyword::factory()->create([
                'current_position' => 10,
                'previous_position' => 10
            ]);

            expect($improvingKeyword->isImproving())->toBeTrue();
            expect($improvingKeyword->isDeclining())->toBeFalse();
            
            expect($decliningKeyword->isDeclining())->toBeTrue();
            expect($decliningKeyword->isImproving())->toBeFalse();
            
            expect($stableKeyword->isImproving())->toBeFalse();
            expect($stableKeyword->isDeclining())->toBeFalse();
        });

        it('handles missing position data gracefully', function () {
            $keywordNoPosition = Keyword::factory()->create([
                'current_position' => null,
                'previous_position' => null
            ]);

            expect($keywordNoPosition->getPositionChange())->toBe(0);
            expect($keywordNoPosition->isImproving())->toBeFalse();
            expect($keywordNoPosition->isDeclining())->toBeFalse();
        });
    });

    describe('Traffic and Value Calculations', function () {
        it('calculates estimated traffic correctly', function () {
            $keyword = Keyword::factory()->create([
                'current_position' => 1,
                'search_volume' => 10000
            ]);

            // Position 1 has ~31.49% CTR, so 10000 * 0.3149 = 3149
            $expectedTraffic = intval(10000 * 0.3149);
            expect($keyword->getEstimatedTraffic())->toBe($expectedTraffic);
        });

        it('returns zero traffic for no position or volume', function () {
            $noPosition = Keyword::factory()->create([
                'current_position' => null,
                'search_volume' => 1000
            ]);

            $noVolume = Keyword::factory()->create([
                'current_position' => 5,
                'search_volume' => null
            ]);

            expect($noPosition->getEstimatedTraffic())->toBe(0);
            expect($noVolume->getEstimatedTraffic())->toBe(0);
        });

        it('calculates estimated value correctly', function () {
            $keyword = Keyword::factory()->create([
                'current_position' => 1,
                'search_volume' => 1000,
                'cpc' => 2.50
            ]);

            $traffic = $keyword->getEstimatedTraffic();
            $expectedValue = $traffic * 2.50;

            expect($keyword->getEstimatedValue())->toBe($expectedValue);
        });

        it('uses default CPC when not provided', function () {
            $keyword = Keyword::factory()->create([
                'current_position' => 1,
                'search_volume' => 1000,
                'cpc' => null
            ]);

            $traffic = $keyword->getEstimatedTraffic();
            expect($keyword->getEstimatedValue())->toBe($traffic * 1.0);
        });
    });

    describe('Difficulty Assessment', function () {
        it('categorizes difficulty levels correctly', function () {
            $easyKeyword = Keyword::factory()->create(['difficulty_score' => 25.0]);
            $mediumKeyword = Keyword::factory()->create(['difficulty_score' => 50.0]);
            $hardKeyword = Keyword::factory()->create(['difficulty_score' => 75.0]);
            $veryHardKeyword = Keyword::factory()->create(['difficulty_score' => 95.0]);

            expect($easyKeyword->getDifficultyLevel())->toBe('easy');
            expect($mediumKeyword->getDifficultyLevel())->toBe('medium');
            expect($hardKeyword->getDifficultyLevel())->toBe('hard');
            expect($veryHardKeyword->getDifficultyLevel())->toBe('very_hard');
        });

        it('handles unknown difficulty', function () {
            $unknownKeyword = Keyword::factory()->create(['difficulty_score' => null]);

            expect($unknownKeyword->getDifficultyLevel())->toBe('unknown');
        });
    });

    describe('Position Updates', function () {
        it('updates position correctly', function () {
            $keyword = Keyword::factory()->create([
                'current_position' => 10,
                'previous_position' => 8
            ]);

            $keyword->updatePosition(5);

            expect($keyword->fresh()->current_position)->toBe(5);
            expect($keyword->fresh()->previous_position)->toBe(10);
            expect($keyword->fresh()->position_last_updated)->toBe(now()->toDateString());
        });
    });

    describe('Casts and Attributes', function () {
        it('casts attributes correctly', function () {
            $keyword = Keyword::factory()->create([
                'categories' => ['brand', 'product'],
                'related_keywords' => ['seo', 'marketing'],
                'tags' => ['important', 'brand'],
                'difficulty_score' => '75.50',
                'cpc' => '12.99',
                'competition' => '0.85',
                'is_tracking_active' => '1',
                'position_last_updated' => '2024-01-01'
            ]);

            expect($keyword->categories)->toBeArray();
            expect($keyword->related_keywords)->toBeArray();
            expect($keyword->tags)->toBeArray();
            expect($keyword->difficulty_score)->toBe(75.50);
            expect($keyword->cpc)->toBe(12.99);
            expect($keyword->competition)->toBe(0.85);
            expect($keyword->is_tracking_active)->toBeTrue();
            expect($keyword->position_last_updated)->toBeInstanceOf(\Carbon\Carbon::class);
        });
    });

    describe('Factory States Validation', function () {
        it('creates branded keywords correctly', function () {
            $brandedKeyword = Keyword::factory()->branded()->create();

            expect($brandedKeyword->categories)->toContain('brand');
            expect($brandedKeyword->intent)->toBe('navigational');
            expect($brandedKeyword->current_position)->toBeWithinRange(1, 5);
        });

        it('creates high volume keywords correctly', function () {
            $highVolumeKeyword = Keyword::factory()->highVolume()->create();

            expect($highVolumeKeyword->search_volume)->toBeGreaterThanOrEqual(10000);
        });
    });

    describe('Business Logic Edge Cases', function () {
        it('handles extreme position values', function () {
            $keyword = Keyword::factory()->create([
                'current_position' => 100,
                'search_volume' => 1000
            ]);

            // Should handle position > 20 gracefully
            expect($keyword->getEstimatedTraffic())->toBeGreaterThanOrEqual(0);
        });

        it('handles decimal positions', function () {
            // Some APIs might return decimal positions
            $keyword = Keyword::factory()->create([
                'current_position' => 1.5,
                'search_volume' => 1000
            ]);

            expect($keyword->getEstimatedTraffic())->toBeGreaterThan(0);
        });
    });

    describe('Tenant Isolation', function () {
        it('maintains proper tenant relationships', function () {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $keyword = Keyword::factory()->for($project)->for($tenant)->create();

            expect($keyword->tenant_id)->toBe($tenant->id);
            expect($keyword->project->tenant_id)->toBe($tenant->id);
        });

        it('isolates keywords by tenant', function () {
            $tenant1 = Tenant::factory()->create();
            $tenant2 = Tenant::factory()->create();
            
            $project1 = Project::factory()->for($tenant1)->create();
            $project2 = Project::factory()->for($tenant2)->create();
            
            $keywords1 = Keyword::factory()->count(3)->for($project1)->for($tenant1)->create();
            $keywords2 = Keyword::factory()->count(2)->for($project2)->for($tenant2)->create();

            expect(Keyword::where('tenant_id', $tenant1->id)->count())->toBe(3);
            expect(Keyword::where('tenant_id', $tenant2->id)->count())->toBe(2);
        });
    });
});