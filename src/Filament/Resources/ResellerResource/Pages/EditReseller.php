<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages;

use Throwable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Mail;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Dashed\DashedEcommerceReseller\Mail\ResellerSetupMail;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Setup\SetupInstructions;
use Dashed\DashedEcommerceReseller\Webhooks\ResellerWebhooks;
use Dashed\DashedEcommerceReseller\Jobs\GenerateResellerFeedsJob;
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
            Action::make('sendSetupMail')
                ->label(__('Installatiemail sturen'))
                ->icon('heroicon-o-envelope')
                ->visible(fn (): bool => $this->record->isActive())
                ->schema([
                    TextInput::make('email')
                        ->label(__('Ontvanger'))
                        ->helperText(__('Bijvoorbeeld de afnemer zelf, of zijn webbouwer.'))
                        ->email()
                        ->required()
                        ->default(fn (): ?string => $this->record->user?->email),
                    Select::make('platform')
                        ->label(__('Platform van de afnemer'))
                        ->options(SetupInstructions::platformOptions())
                        ->default('shopify')
                        ->required(),
                    Toggle::make('with_api_key')
                        ->label(__('Nieuwe API-sleutel meesturen'))
                        ->helperText(__('Alleen nodig voor een eigen developer. Er wordt een nieuwe sleutel gemaakt; bestaande sleutels blijven werken.'))
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    // De feeds worden nooit in dit verzoek aangemaakt. Dat is
                    // hetzelfde werk als GenerateResellerFeedsJob, die er tien
                    // minuten voor krijgt, en een catalogus van enige omvang
                    // liep zo tegen de gateway timeout van de webserver aan:
                    // de mail werd dan nooit bereikt. Ontbreekt er een bestand,
                    // dan gaat er nu een generatie de wachtrij in en gaat de
                    // mail gewoon de deur uit. Haalt de afnemer zijn link op
                    // voordat die klaar is, dan krijgt hij de 503 met
                    // Retry-After van de feedroute, en dat lost zichzelf op.
                    // Zonder vertraging, anders dan dispatchFor(): daar bundelt
                    // de vertraging honderd synchronisaties tot één bestand, hier
                    // wacht een beheerder op een mail die hij net verstuurd heeft.
                    $ontbreekt = collect(ResellerProfile::FEED_FORMATS)
                        ->contains(fn (string $format): bool => $this->record->feedGeneratedAt($format) === null);

                    if ($ontbreekt) {
                        GenerateResellerFeedsJob::dispatch($this->record->id);
                    }

                    $token = $data['with_api_key']
                        ? $this->record->createToken(__('Installatiemail :datum', ['datum' => now()->format('d-m-Y')]))
                        : null;

                    try {
                        Mail::to($data['email'])->send(new ResellerSetupMail($this->record, $data['platform'], $token?->plainTextToken));
                    } catch (Throwable $e) {
                        report($e);

                        // Een sleutel die nooit is verstuurd mag niet blijven
                        // hangen: de afnemer heeft hem niet gezien en kan hem
                        // dus ook niet gebruiken, en anders zou hij als
                        // vergeten, ongebruikte sleutel op de afnemer blijven
                        // staan.
                        $token?->accessToken->delete();

                        Notification::make()
                            ->title(__('De mail kon niet worden verstuurd.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    rescue(fn () => activity()
                        ->performedOn($this->record)
                        ->withProperties(['email' => $data['email'], 'platform' => $data['platform'], 'with_api_key' => (bool) $data['with_api_key']])
                        ->log('reseller:setup-mail-sent'), report: false);

                    $melding = Notification::make()->title(__('Installatiemail verstuurd'))->success();

                    if ($ontbreekt) {
                        $melding->body(__('De feeds worden nu op de achtergrond aangemaakt. Haalt de afnemer zijn link op voordat ze klaar zijn, dan krijgt hij het verzoek om het zo opnieuw te proberen.'));
                    }

                    $melding->send();
                }),
            Action::make('newFeedToken')
                ->label(__('Nieuwe feedsleutel'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('De oude links werken meteen niet meer. De afnemer moet de nieuwe link in Matrixify of WP All Import zetten.'))
                ->action(function (): void {
                    // Geen nieuwe generatie inplannen: de bestanden staan per
                    // profiel-id opgeslagen, niet per token, dus ze blijven
                    // onder de nieuwe link gewoon geldig.
                    $this->record->regenerateFeedToken();
                    Notification::make()->title(__('Nieuwe feedsleutel gemaakt'))->success()->send();
                }),
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
