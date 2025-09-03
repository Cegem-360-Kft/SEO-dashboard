<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\PositionOverviewWidget;
use App\Filament\Admin\Widgets\PerformanceStatsWidget;
use App\Filament\Admin\Widgets\QuickStatsWidget;
use App\Filament\Admin\Widgets\RecentAlertsWidget;
use App\Models\Project;
use App\Models\Keyword;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Support\Enums\IconPosition;

class Seodashboard extends Dashboard
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string $view = 'filament-panels::pages.dashboard';

    protected static ?string $title = 'SEO Dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public function getColumns(): int | string | array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    public function getWidgets(): array
    {
        return [
            QuickStatsWidget::class,
            PositionOverviewWidget::class,
            PerformanceStatsWidget::class,
            RecentAlertsWidget::class,
        ];
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh Data')
                ->icon('heroicon-m-arrow-path')
                ->action(function () {
                    $this->redirect(request()->header('Referer'));
                })
                ->color('gray'),
            
            Action::make('export')
                ->label('Export Report')
                ->icon('heroicon-m-arrow-down-tray')
                ->action(function () {
                    // Export functionality would go here
                    $this->notify('success', 'Export started! You will receive an email when ready.');
                })
                ->color('info'),
            
            Action::make('newProject')
                ->label('New Project')
                ->icon('heroicon-m-plus')
                ->url('/admin/projects/create')
                ->color('success'),
        ];
    }

    public function filtersForm(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form
            ->schema([
                Select::make('project_id')
                    ->label('Filter by Project')
                    ->options(Project::where('tenant_id', auth()->user()->tenant_id)->pluck('name', 'id'))
                    ->placeholder('All Projects')
                    ->searchable(),
                
                Select::make('date_range')
                    ->label('Date Range')
                    ->options([
                        '7' => 'Last 7 days',
                        '30' => 'Last 30 days',
                        '90' => 'Last 90 days',
                        '365' => 'Last year',
                    ])
                    ->default('30'),
                
                DatePicker::make('start_date')
                    ->label('Custom Start Date'),
                
                DatePicker::make('end_date')
                    ->label('Custom End Date'),
            ]);
    }

    public function getFiltersFormWidth(): string
    {
        return '4xl';
    }
}