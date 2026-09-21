<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceReseller\Webhooks\ResellerWebhooks;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource;

class EditReseller extends EditRecord
{
    protected static string $resource = ResellerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['webhook_url'] = $this->record->webhookSubscription?->url;

        return $data;
    }

    protected function afterSave(): void
    {
        ResellerWebhooks::configure($this->record->fresh(), $this->data['webhook_url'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createKey')
                ->label(__('Nieuwe sleutel'))
                ->icon('heroicon-o-key')
                ->schema([
                    TextInput::make('name')
                        ->label(__('Naam van de sleutel'))
                        ->helperText(__('Bijvoorbeeld de omgeving of de koppelpartij, zodat je hem later terugvindt.'))
                        ->default('Productie')
                        ->required()
                        ->maxLength(100),
                ])
                ->action(function (array $data): void {
                    $token = $this->record->createToken($data['name']);
                    $this->showSecretOnce(__('Sleutel aangemaakt'), $token->plainTextToken);
                }),
            Action::make('newSecret')
                ->label(__('Nieuw webhookgeheim'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Het oude geheim werkt meteen niet meer. De afnemer moet het nieuwe geheim in zijn koppeling zetten.'))
                ->visible(fn (): bool => $this->record->webhookSubscription !== null)
                ->action(function (): void {
                    $this->showSecretOnce(__('Nieuw webhookgeheim'), ResellerWebhooks::regenerateSecret($this->record));
                }),
            Action::make('ping')
                ->label(__('Testbericht sturen'))
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->record->webhookSubscription?->is_active)
                ->action(function (): void {
                    ResellerWebhooks::ping($this->record);
                    Notification::make()
                        ->title(__('Testbericht klaargezet'))
                        ->body(__('Het resultaat staat zo onder Webhook-bezorgingen.'))
                        ->success()
                        ->send();
                }),
            Action::make('reactivate')
                ->label(__('Webhook weer aanzetten'))
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->webhookSubscription !== null && ! $this->record->webhookSubscription->is_active)
                ->action(function (): void {
                    ResellerWebhooks::reactivate($this->record);
                    $this->record->unsetRelation('webhookSubscription');
                    Notification::make()->title(__('Webhook staat weer aan'))->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function showSecretOnce(string $title, string $secret): void
    {
        Notification::make()
            ->title($title)
            ->body(new HtmlString(
                e(__('Kopieer hem nu: hij wordt niet opnieuw getoond.'))
                . '<br><code style="word-break: break-all; user-select: all;">' . e($secret) . '</code>'
            ))
            ->success()
            ->persistent()
            ->send();
    }
}
