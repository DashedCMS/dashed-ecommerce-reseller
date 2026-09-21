<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\RelationManagers;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\RelationManagers\RelationManager;

class ApiLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'apiLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('API-aanroepen');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Tijdstip'))->dateTime('d-m-Y H:i:s'),
                TextColumn::make('method')->label(__('Methode')),
                TextColumn::make('path')->label(__('Pad'))->limit(60),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('duration_ms')
                    ->label(__('Duur'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : __(':ms ms', ['ms' => $state])),
                TextColumn::make('ip')->label(__('IP-adres'))->placeholder('-'),
            ]);
    }
}
