<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\Keyword;
use App\Models\KeywordPosition;
use Filament\Widgets\ChartWidget;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Support\Carbon;

final class PositionOverviewWidget extends ChartWidget
{
    protected static ?string $heading = 'Position Trends (Last 30 Days)';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $tenantId = auth()->user()->tenant_id;

        // Get position data for the last 30 days
        $data = Trend::model(KeywordPosition::class)
            ->between(
                start: now()->subDays(30),
                end: now(),
            )
            ->perDay()
            ->average('position');

        // Get top 10, top 20 trends
        $top10Trend = [];
        $top20Trend = [];
        $totalKeywords = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');

            $dayTop10 = Keyword::query()->where('tenant_id', $tenantId)
                ->whereBetween('current_position', [1, 10])
                ->whereDate('position_last_updated', $date)
                ->count();

            $dayTop20 = Keyword::query()->where('tenant_id', $tenantId)
                ->whereBetween('current_position', [1, 20])
                ->whereDate('position_last_updated', $date)
                ->count();

            $dayTotal = Keyword::query()->where('tenant_id', $tenantId)
                ->whereNotNull('current_position')
                ->whereDate('position_last_updated', $date)
                ->count();

            $top10Trend[] = $dayTop10;
            $top20Trend[] = $dayTop20;
            $totalKeywords[] = $dayTotal;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Top 10 Keywords',
                    'data' => $top10Trend,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Top 20 Keywords',
                    'data' => $top20Trend,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Average Position',
                    'data' => $data->map(fn (TrendValue $value): float|int => $value->aggregate ? round($value->aggregate, 1) : 0)->toArray(),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'yAxisID' => 'y1',
                    'type' => 'line',
                ],
            ],
            'labels' => $data->map(fn (TrendValue $value): string => Carbon::parse($value->date)->format('M j'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Number of Keywords',
                    ],
                ],
                'y1' => [
                    'type' => 'linear',
                    'display' => true,
                    'position' => 'right',
                    'title' => [
                        'display' => true,
                        'text' => 'Average Position',
                    ],
                    'reverse' => true, // Lower positions are better
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Date',
                    ],
                ],
            ],
            'interaction' => [
                'intersect' => false,
                'mode' => 'index',
            ],
        ];
    }
}
