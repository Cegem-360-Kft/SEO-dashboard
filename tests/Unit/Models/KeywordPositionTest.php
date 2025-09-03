<?php

declare(strict_types=1);

use App\Models\Keyword;
use App\Models\KeywordPosition;
use App\Models\Project;
use App\Models\Tenant;
use Carbon\Carbon;

describe('KeywordPosition Model', function (): void {

    describe('Factory and Creation', function (): void {
        it('can be created with valid data', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $keyword = Keyword::factory()->for($project)->for($tenant)->create();
            $position = KeywordPosition::factory()->for($keyword)->for($tenant)->create();

            expect($position)->toBeInstanceOf(KeywordPosition::class);
            expect($position->tenant_id)->toBe($tenant->id);
            expect($position->keyword_id)->toBe($keyword->id);
            expect($position->position)->toHaveValidPosition();
        });

        it('calculates estimated traffic and value correctly', function (): void {
            $position = KeywordPosition::factory()->create([
                'position' => 1,
                'estimated_traffic' => 1000,
                'estimated_value' => 250.50,
            ]);

            expect($position->estimated_traffic)->toBe(1000);
            expect($position->estimated_value)->toBe(250.50);
        });

        it('can create top ranking positions', function (): void {
            $topPosition = KeywordPosition::factory()->topThree()->create();
            $top10Position = KeywordPosition::factory()->topTen()->create();

            expect($topPosition->position)->toBeWithinRange(1, 3);
            expect($top10Position->position)->toBeWithinRange(1, 10);
        });

        it('can create positions with special features', function (): void {
            $featuredPosition = KeywordPosition::factory()->withFeaturedSnippet()->create();
            $localPosition = KeywordPosition::factory()->withLocalPack()->create();

            expect($featuredPosition->is_featured_snippet)->toBeTrue();
            expect($featuredPosition->position)->toBeWithinRange(1, 5);
            expect($localPosition->is_local_pack)->toBeTrue();
        });
    });

    describe('Relationships', function (): void {
        it('belongs to a keyword', function (): void {
            $keyword = Keyword::factory()->create();
            $position = KeywordPosition::factory()->for($keyword)->for($keyword->tenant)->create();

            expect($position->keyword)->toBeInstanceOf(Keyword::class);
            expect($position->keyword->id)->toBe($keyword->id);
        });
    });

    describe('Scopes', function (): void {
        it('scopes by date', function (): void {
            $date = '2024-01-15';
            $position1 = KeywordPosition::factory()->forDate($date)->create();
            $position2 = KeywordPosition::factory()->forDate('2024-01-20')->create();

            $datePositions = KeywordPosition::forDate($date)->get();

            expect($datePositions)->toContain($position1);
            expect($datePositions)->not->toContain($position2);
        });

        it('scopes by search engine', function (): void {
            $googlePosition = KeywordPosition::factory()->forSearchEngine('google')->create();
            $bingPosition = KeywordPosition::factory()->forSearchEngine('bing')->create();

            $googlePositions = KeywordPosition::forSearchEngine('google')->get();

            expect($googlePositions)->toContain($googlePosition);
            expect($googlePositions)->not->toContain($bingPosition);
        });

        it('scopes by device', function (): void {
            $desktopPosition = KeywordPosition::factory()->forDevice('desktop')->create();
            $mobilePosition = KeywordPosition::factory()->forDevice('mobile')->create();

            $desktopPositions = KeywordPosition::forDevice('desktop')->get();

            expect($desktopPositions)->toContain($desktopPosition);
            expect($desktopPositions)->not->toContain($mobilePosition);
        });

        it('scopes top 10 positions', function (): void {
            $topPosition = KeywordPosition::factory()->create(['position' => 5]);
            $lowPosition = KeywordPosition::factory()->create(['position' => 25]);

            $top10Positions = KeywordPosition::inTop10()->get();

            expect($top10Positions)->toContain($topPosition);
            expect($top10Positions)->not->toContain($lowPosition);
        });

        it('scopes positions with featured snippets', function (): void {
            $featuredPosition = KeywordPosition::factory()->withFeaturedSnippet()->create();
            $regularPosition = KeywordPosition::factory()->create(['is_featured_snippet' => false]);

            $featuredPositions = KeywordPosition::withFeaturedSnippet()->get();

            expect($featuredPositions)->toContain($featuredPosition);
            expect($featuredPositions)->not->toContain($regularPosition);
        });

        it('scopes positions with local pack', function (): void {
            $localPosition = KeywordPosition::factory()->withLocalPack()->create();
            $regularPosition = KeywordPosition::factory()->create(['is_local_pack' => false]);

            $localPositions = KeywordPosition::withLocalPack()->get();

            expect($localPositions)->toContain($localPosition);
            expect($localPositions)->not->toContain($regularPosition);
        });

        it('scopes by date range', function (): void {
            $position1 = KeywordPosition::factory()->create(['date' => '2024-01-10']);
            $position2 = KeywordPosition::factory()->create(['date' => '2024-01-15']);
            $position3 = KeywordPosition::factory()->create(['date' => '2024-01-25']);

            $rangePositions = KeywordPosition::forDateRange('2024-01-12', '2024-01-20')->get();

            expect($rangePositions)->toContain($position2);
            expect($rangePositions)->not->toContain($position1);
            expect($rangePositions)->not->toContain($position3);
        });
    });

    describe('Analytics Methods', function (): void {
        it('correctly identifies improvement', function (): void {
            $current = KeywordPosition::factory()->create(['position' => 5]);
            $previous = KeywordPosition::factory()->create(['position' => 10]);

            expect($current->hasImproved($previous))->toBeTrue();
            expect($current->hasDeclined($previous))->toBeFalse();
        });

        it('correctly identifies decline', function (): void {
            $current = KeywordPosition::factory()->create(['position' => 15]);
            $previous = KeywordPosition::factory()->create(['position' => 8]);

            expect($current->hasDeclined($previous))->toBeTrue();
            expect($current->hasImproved($previous))->toBeFalse();
        });

        it('handles no previous position', function (): void {
            $current = KeywordPosition::factory()->create(['position' => 10]);

            expect($current->hasImproved(null))->toBeFalse();
            expect($current->hasDeclined(null))->toBeFalse();
            expect($current->getPositionChange(null))->toBe(0);
        });

        it('calculates position change correctly', function (): void {
            $current = KeywordPosition::factory()->create(['position' => 5]);
            $previous = KeywordPosition::factory()->create(['position' => 12]);

            expect($current->getPositionChange($previous))->toBe(7); // Improved by 7 positions
        });

        it('identifies ranking positions', function (): void {
            $rankingPosition = KeywordPosition::factory()->create(['position' => 15]);
            $notRankingPosition = KeywordPosition::factory()->create(['position' => null]);

            expect($rankingPosition->isRanking())->toBeTrue();
            expect($notRankingPosition->isRanking())->toBeFalse();
        });
    });

    describe('Visibility Score Calculation', function (): void {
        it('calculates visibility score correctly for different positions', function (): void {
            $position1 = KeywordPosition::factory()->create(['position' => 1]);
            $position5 = KeywordPosition::factory()->create(['position' => 5]);
            $position15 = KeywordPosition::factory()->create(['position' => 15]);
            $position50 = KeywordPosition::factory()->create(['position' => 50]);

            expect($position1->getVisibilityScore())->toBe(100.0);
            expect($position5->getVisibilityScore())->toBe(50.0);
            expect($position15->getVisibilityScore())->toBe(10.0);
            expect($position50->getVisibilityScore())->toBe(1.0);
        });

        it('returns zero visibility for no position', function (): void {
            $position = KeywordPosition::factory()->create(['position' => null]);

            expect($position->getVisibilityScore())->toBe(0.0);
        });
    });

    describe('CTR Estimation', function (): void {
        it('estimates CTR correctly for different positions', function (): void {
            $position1 = KeywordPosition::factory()->create(['position' => 1]);
            $position2 = KeywordPosition::factory()->create(['position' => 2]);
            $position10 = KeywordPosition::factory()->create(['position' => 10]);
            $position50 = KeywordPosition::factory()->create(['position' => 50]);

            expect($position1->getCtrEstimate())->toBe(31.49);
            expect($position2->getCtrEstimate())->toBe(15.55);
            expect($position10->getCtrEstimate())->toBe(2.08);
            expect($position50->getCtrEstimate())->toBe(1.0); // Default for positions > 10
        });

        it('returns zero CTR for no position', function (): void {
            $position = KeywordPosition::factory()->create(['position' => null]);

            expect($position->getCtrEstimate())->toBe(0.0);
        });
    });

    describe('Special Features Detection', function (): void {
        it('detects special features correctly', function (): void {
            $featuredPosition = KeywordPosition::factory()->create([
                'is_featured_snippet' => true,
                'is_local_pack' => false,
                'serp_features' => [],
            ]);

            $localPosition = KeywordPosition::factory()->create([
                'is_featured_snippet' => false,
                'is_local_pack' => true,
                'serp_features' => [],
            ]);

            $serpFeaturesPosition = KeywordPosition::factory()->create([
                'is_featured_snippet' => false,
                'is_local_pack' => false,
                'serp_features' => ['image_pack', 'people_also_ask'],
            ]);

            $regularPosition = KeywordPosition::factory()->create([
                'is_featured_snippet' => false,
                'is_local_pack' => false,
                'serp_features' => [],
            ]);

            expect($featuredPosition->hasSpecialFeature())->toBeTrue();
            expect($localPosition->hasSpecialFeature())->toBeTrue();
            expect($serpFeaturesPosition->hasSpecialFeature())->toBeTrue();
            expect($regularPosition->hasSpecialFeature())->toBeFalse();
        });
    });

    describe('Casts and Attributes', function (): void {
        it('casts attributes correctly', function (): void {
            $position = KeywordPosition::factory()->create([
                'date' => '2024-01-15',
                'serp_features' => ['featured_snippet', 'image_pack'],
                'estimated_value' => '125.75',
                'is_featured_snippet' => '1',
                'is_local_pack' => '0',
                'is_paid_above' => '1',
                'checked_at' => '2024-01-15 10:30:00',
            ]);

            expect($position->date)->toBeInstanceOf(Carbon::class);
            expect($position->serp_features)->toBeArray();
            expect($position->estimated_value)->toBe(125.75);
            expect($position->is_featured_snippet)->toBeTrue();
            expect($position->is_local_pack)->toBeFalse();
            expect($position->is_paid_above)->toBeTrue();
            expect($position->checked_at)->toBeInstanceOf(Carbon::class);
        });
    });

    describe('Factory States Validation', function (): void {
        it('creates mobile and desktop positions correctly', function (): void {
            $mobilePosition = KeywordPosition::factory()->mobile()->create();
            $desktopPosition = KeywordPosition::factory()->desktop()->create();

            expect($mobilePosition->device)->toBe('mobile');
            expect($desktopPosition->device)->toBe('desktop');
        });

        it('creates high value positions correctly', function (): void {
            $highValuePosition = KeywordPosition::factory()->highValue()->create();

            expect($highValuePosition->position)->toBeWithinRange(1, 10);
            expect($highValuePosition->estimated_value)->toBeGreaterThan(0);
        });

        it('creates recent positions correctly', function (): void {
            $recentPosition = KeywordPosition::factory()->recent()->create();

            expect($recentPosition->date)->toBeGreaterThanOrEqual(now()->subDays(3)->format('Y-m-d'));
        });
    });

    describe('Business Logic Edge Cases', function (): void {
        it('handles identical positions for comparison', function (): void {
            $current = KeywordPosition::factory()->create(['position' => 10]);
            $previous = KeywordPosition::factory()->create(['position' => 10]);

            expect($current->hasImproved($previous))->toBeFalse();
            expect($current->hasDeclined($previous))->toBeFalse();
            expect($current->getPositionChange($previous))->toBe(0);
        });

        it('handles extreme position values', function (): void {
            $extremePosition = KeywordPosition::factory()->create(['position' => 999]);

            expect($extremePosition->getVisibilityScore())->toBe(1.0);
            expect($extremePosition->getCtrEstimate())->toBe(1.0);
            expect($extremePosition->isRanking())->toBeTrue();
        });
    });

    describe('Tenant Isolation', function (): void {
        it('maintains proper tenant relationships', function (): void {
            $tenant = Tenant::factory()->create();
            $project = Project::factory()->for($tenant)->create();
            $keyword = Keyword::factory()->for($project)->for($tenant)->create();
            $position = KeywordPosition::factory()->for($keyword)->for($tenant)->create();

            expect($position->tenant_id)->toBe($tenant->id);
            expect($position->keyword->tenant_id)->toBe($tenant->id);
        });

        it('isolates positions by tenant', function (): void {
            $tenant1 = Tenant::factory()->create();
            $tenant2 = Tenant::factory()->create();

            $project1 = Project::factory()->for($tenant1)->create();
            $project2 = Project::factory()->for($tenant2)->create();

            $keyword1 = Keyword::factory()->for($project1)->for($tenant1)->create();
            $keyword2 = Keyword::factory()->for($project2)->for($tenant2)->create();

            $positions1 = KeywordPosition::factory()->count(3)->for($keyword1)->for($tenant1)->create();
            $positions2 = KeywordPosition::factory()->count(2)->for($keyword2)->for($tenant2)->create();

            expect(KeywordPosition::query()->where('tenant_id', $tenant1->id)->count())->toBe(3);
            expect(KeywordPosition::query()->where('tenant_id', $tenant2->id)->count())->toBe(2);
        });
    });
});
