<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Projects\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->required(),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('url')
                    ->required(),
                TextInput::make('domain')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Textarea::make('target_countries')
                    ->columnSpanFull(),
                Textarea::make('target_languages')
                    ->columnSpanFull(),
                Textarea::make('search_engines')
                    ->required()
                    ->default('["google"]')
                    ->columnSpanFull(),
                Textarea::make('devices')
                    ->required()
                    ->default('["desktop", "mobile"]')
                    ->columnSpanFull(),
                Textarea::make('integrations')
                    ->columnSpanFull(),
                Textarea::make('settings')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                DateTimePicker::make('last_crawled_at'),
                DateTimePicker::make('last_positions_updated_at'),
            ]);
    }
}
