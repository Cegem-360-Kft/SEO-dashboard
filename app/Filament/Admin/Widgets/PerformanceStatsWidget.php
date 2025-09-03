<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\Keyword;
use Filament\Widgets\ChartWidget;

final class PerformanceStatsWidget extends ChartWidget
{
    protected static ?string $heading = 'Keyword Distribution by Position';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $tenantId = auth()->user()->tenant_id;

        // Get position distribution
        $positions = [
            'Top 3 (1-3)' => Keyword::query()->where('tenant_id', $tenantId)
                ->whereBetween('current_position', [1, 3])
                ->count(),
            'Top 10 (4-10)' => Keyword::query()->where('tenant_id', $tenantId)
                ->whereBetween('current_position', [4, 10])
                ->count(),
            'Top 20 (11-20)' => Keyword::query()->where('tenant_id', $tenantId)
                ->whereBetween('current_position', [11, 20])
                ->count(),
            'Top 50 (21-50)' => Keyword::query()->where('tenant_id', $tenantId)
                ->whereBetween('current_position', [21, 50])
                ->count(),
            'Beyond 50' => Keyword::query()->where('tenant_id', $tenantId)
                ->where('current_position', '>', 50)
                ->count(),
            'Not Ranked' => Keyword::query()->where('tenant_id', $tenantId)
                ->whereNull('current_position')
                ->count(),
        ];

        return [
            'datasets' => [
                [
                    'label' => 'Keywords by Position Range',
                    'data' => array_values($positions),
                    'backgroundColor' => [
                        '#10b981', // Green for top 3
                        '#f59e0b', // Amber for 4-10
                        '#3b82f6', // Blue for 11-20
                        '#8b5cf6', // Purple for 21-50
                        '#ef4444', // Red for beyond 50
                        '#6b7280', // Gray for not ranked
                    ],
                    'borderWidth' => 2,
                ],
            ],
            'labels' => array_keys($positions),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'maintainAspectRatio' => false,
        ];
    }
}
