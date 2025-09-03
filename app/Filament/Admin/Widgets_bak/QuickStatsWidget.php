<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Keyword;
use App\Models\Project;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QuickStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $tenantId = auth()->user()->tenant_id;
        
        // Total projects
        $totalProjects = Project::where('tenant_id', $tenantId)->count();
        $activeProjects = Project::where('tenant_id', $tenantId)->where('is_active', true)->count();
        
        // Total keywords
        $totalKeywords = Keyword::where('tenant_id', $tenantId)->count();
        $activeKeywords = Keyword::where('tenant_id', $tenantId)
            ->where('is_tracking_active', true)
            ->count();
        
        // Position stats
        $top10Keywords = Keyword::where('tenant_id', $tenantId)
            ->whereBetween('current_position', [1, 10])
            ->count();
        
        $top3Keywords = Keyword::where('tenant_id', $tenantId)
            ->whereBetween('current_position', [1, 3])
            ->count();
        
        // Average position
        $averagePosition = Keyword::where('tenant_id', $tenantId)
            ->whereNotNull('current_position')
            ->avg('current_position');
        
        // Trending keywords (improving vs declining)
        $improvingKeywords = Keyword::where('tenant_id', $tenantId)
            ->whereRaw('previous_position > current_position')
            ->whereNotNull('previous_position')
            ->whereNotNull('current_position')
            ->count();
        
        $decliningKeywords = Keyword::where('tenant_id', $tenantId)
            ->whereRaw('previous_position < current_position')
            ->whereNotNull('previous_position')
            ->whereNotNull('current_position')
            ->count();
        
        // Total users
        $totalUsers = User::where('tenant_id', $tenantId)->count();

        return [
            Stat::make('Total Projects', $totalProjects)
                ->description($activeProjects . ' active projects')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            
            Stat::make('Total Keywords', number_format($totalKeywords))
                ->description($activeKeywords . ' actively tracked')
                ->descriptionIcon('heroicon-m-eye')
                ->color('info'),
            
            Stat::make('Top 10 Positions', $top10Keywords)
                ->description($top3Keywords . ' in top 3')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('warning'),
            
            Stat::make('Average Position', $averagePosition ? number_format($averagePosition, 1) : 'N/A')
                ->description('Across all tracked keywords')
                ->descriptionIcon($averagePosition <= 20 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($averagePosition && $averagePosition <= 20 ? 'success' : 'danger'),
            
            Stat::make('Position Changes', $improvingKeywords)
                ->description($decliningKeywords . ' declining, ' . ($improvingKeywords - $decliningKeywords) . ' net change')
                ->descriptionIcon($improvingKeywords >= $decliningKeywords ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($improvingKeywords >= $decliningKeywords ? 'success' : 'danger'),
            
            Stat::make('Team Members', $totalUsers)
                ->description('Active users in tenant')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }

    public function getColumns(): int
    {
        return 3;
    }
}