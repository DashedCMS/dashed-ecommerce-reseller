<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\RelationManagers;

use Filament\Tables\Table;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\RelationManagers\RelationManager;

class TokensRelationManager extends RelationManager
{
    protected static string $relationship = 'tokens';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('API-sleutels');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Naam')),
                TextColumn::make('created_at')->label(__('Aangemaakt'))->dateTime('d-m-Y H:i'),
                TextColumn::make('last_used_at')->label(__('Laatst gebruikt'))->since()->placeholder(__('Nog nooit')),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('Intrekken'))
                    ->modalHeading(__('Sleutel intrekken'))
                    ->modalDescription(__('Een koppeling die deze sleutel gebruikt krijgt meteen geen toegang meer.')),
            ]);
    }
}
