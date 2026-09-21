<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\RelationManagers;

use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedCore\Models\WebhookDelivery;
use Filament\Resources\RelationManagers\RelationManager;
use Dashed\DashedCore\Webhooks\Outgoing\WebhookDispatcher;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'webhookDeliveries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Webhook-bezorgingen');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Aangemaakt'))->dateTime('d-m-Y H:i:s'),
                TextColumn::make('event')->label(__('Gebeurtenis'))->badge(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WebhookDelivery::STATUS_SENT => 'success',
                        WebhookDelivery::STATUS_FAILED => 'warning',
                        WebhookDelivery::STATUS_ABANDONED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('attempt')->label(__('Poging')),
                TextColumn::make('response_code')->label(__('Antwoord'))->placeholder('-'),
                TextColumn::make('error')->label(__('Fout'))->limit(60)->placeholder('-'),
            ])
            ->recordActions([
                Action::make('resend')
                    ->label(__('Opnieuw versturen'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (WebhookDelivery $record): bool => $record->status === WebhookDelivery::STATUS_ABANDONED
                        && (bool) $record->subscription?->is_active)
                    ->action(fn (WebhookDelivery $record) => app(WebhookDispatcher::class)
                        ->dispatch($record->subscription, $record->event, $record->payload)),
            ]);
    }
}
