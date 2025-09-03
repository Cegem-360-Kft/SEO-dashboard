<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Keyword;
use App\Models\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentAlertsWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent Position Changes';

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Keyword::query()
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->whereNotNull('current_position')
                    ->whereNotNull('previous_position')
                    ->whereRaw('ABS(current_position - previous_position) >= 5') // Significant changes only
                    ->with(['project'])
                    ->orderByRaw('ABS(current_position - previous_position) DESC')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('keyword')
                    ->limit(40)
                    ->searchable()
                    ->weight('bold'),
                
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->badge()
                    ->color('info'),
                
                Tables\Columns\TextColumn::make('previous_position')
                    ->label('From')
                    ->badge()
                    ->color('gray'),
                
                Tables\Columns\TextColumn::make('current_position')
                    ->label('To')
                    ->badge()
                    ->color(fn ($record) => match(true) {
                        $record->current_position <= 3 => 'success',
                        $record->current_position <= 10 => 'warning',
                        $record->current_position <= 20 => 'info',
                        default => 'danger'
                    }),
                
                Tables\Columns\TextColumn::make('position_change')
                    ->label('Change')
                    ->getStateUsing(fn (Keyword $record) => $record->getPositionChange())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn ($state) => $state > 0 ? '+' . $state : (string) $state)
                    ->icon(fn ($state) => $state > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'),
                
                Tables\Columns\TextColumn::make('position_last_updated')
                    ->label('Updated')
                    ->date()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low' => 'gray',
                        'medium' => 'info',
                        'high' => 'warning',
                        'critical' => 'danger',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Keyword $record): string => '/admin/keywords/' . $record->id)
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No Significant Changes')
            ->emptyStateDescription('No keywords have moved 5+ positions recently.')
            ->emptyStateIcon('heroicon-o-chart-bar')
            ->striped();
    }
}