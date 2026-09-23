<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources;

use Closure;
use UnitEnum;
use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Dashed\DashedCore\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Dashed\DashedCore\Webhooks\Outgoing\UrlGuard;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedCore\Classes\QueryHelpers\TokenizedSearch;
use Dashed\DashedEcommerceReseller\Setup\SetupInstructions;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages\EditReseller;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages\ListResellers;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\Pages\CreateReseller;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\RelationManagers\TokensRelationManager;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\RelationManagers\ApiLogsRelationManager;
use Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource\RelationManagers\DeliveriesRelationManager;

class ResellerResource extends Resource
{
    protected static ?string $model = ResellerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Gebruikers';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('Afnemers');
    }

    public static function getModelLabel(): string
    {
        return __('Afnemer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Afnemers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make(__('Afnemer'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label(__('Klantaccount'))
                        ->helperText(__('Alleen klantaccounts. Een account dat in het CMS inlogt kan geen afnemer zijn, want een API-sleutel slaat de tweestapsverificatie over.'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::searchCustomers($search))
                        ->getOptionLabelUsing(fn ($value): string => self::customerLabel(User::find($value)))
                        ->unique(table: ResellerProfile::class, column: 'user_id', ignoreRecord: true)
                        ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                            $user = User::find($value);

                            if ($user && $user->mustLoginViaPanel()) {
                                $fail(__('Een account dat in het CMS inlogt kan geen afnemer zijn.'));
                            }
                        })
                        ->disabledOn('edit')
                        ->live(),
                    Select::make('assortment_id')
                        ->label(__('Assortiment'))
                        ->relationship('assortment', 'name')
                        ->preload()
                        ->required(),
                    Toggle::make('enabled')
                        ->label(__('API-toegang aan'))
                        ->default(true),
                    TextEntry::make('price_group_info')
                        ->label(__('Prijsgroep'))
                        ->state(fn (Get $get): string => self::priceGroupInfo($get('user_id'))),
                ]),
            Section::make(__('Webhook'))
                ->description(__('Wij sturen een ondertekend bericht naar dit adres zodra een product voor deze afnemer verandert of uit zijn assortiment gaat. Laat leeg om geen berichten te sturen.'))
                ->columnSpanFull()
                ->schema([
                    TextInput::make('webhook_url')
                        ->label(__('Webhook-URL'))
                        ->placeholder(__('https://'))
                        ->maxLength(2048)
                        ->dehydrated(false)
                        ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                            if (filled($value) && ($reason = UrlGuard::check((string) $value)) !== null) {
                                $fail($reason);
                            }
                        }),
                    TextEntry::make('webhook_status')
                        ->label(__('Status'))
                        ->state(fn (?ResellerProfile $record): string => self::webhookStatus($record))
                        ->visibleOn('edit'),
                ]),
            Section::make(__('Feeds voor de afnemer'))
                ->description(__('Met deze links zet de afnemer ons assortiment zelf in zijn winkel. De twee CSV-feeds zijn voor Shopify en WooCommerce, de JSON- en XML-feed zijn voor een eigen koppeling. Stuur hem de uitleg met de knop Installatiemail sturen.'))
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->visibleOn('edit')
                ->schema([
                    TextEntry::make('shopify_feed')
                        ->label(__('Shopify (Matrixify)'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedUrl('shopify') ?? '-')
                        ->copyable(),
                    TextEntry::make('woocommerce_feed')
                        ->label(__('WooCommerce (WP All Import)'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedUrl('woocommerce') ?? '-')
                        ->copyable(),
                    TextEntry::make('json_feed')
                        ->label(__('JSON (eigen koppeling)'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedUrl('json') ?? '-')
                        ->copyable(),
                    TextEntry::make('xml_feed')
                        ->label(__('XML (eigen koppeling)'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedUrl('xml') ?? '-')
                        ->copyable(),
                    TextEntry::make('feed_generated_shopify')
                        ->label(__('Shopify laatst bijgewerkt'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedGeneratedAt('shopify')?->diffForHumans() ?? __('Nog niet, gebeurt bij de eerste ophaling')),
                    TextEntry::make('feed_generated_woocommerce')
                        ->label(__('WooCommerce laatst bijgewerkt'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedGeneratedAt('woocommerce')?->diffForHumans() ?? __('Nog niet, gebeurt bij de eerste ophaling')),
                    TextEntry::make('feed_generated_json')
                        ->label(__('JSON laatst bijgewerkt'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedGeneratedAt('json')?->diffForHumans() ?? __('Nog niet, gebeurt bij de eerste ophaling')),
                    TextEntry::make('feed_generated_xml')
                        ->label(__('XML laatst bijgewerkt'))
                        ->state(fn (?ResellerProfile $record): string => $record?->feedGeneratedAt('xml')?->diffForHumans() ?? __('Nog niet, gebeurt bij de eerste ophaling')),
                    TextEntry::make('feed_last_fetched')
                        ->label(__('Laatst opgehaald'))
                        ->state(fn (?ResellerProfile $record): string => self::lastFeedFetch($record)),
                    TextEntry::make('setup_shopify')
                        ->label(__('Stappen voor Shopify'))
                        ->state(fn (?ResellerProfile $record) => $record ? new HtmlString(SetupInstructions::html($record, 'shopify')) : null)
                        ->html(),
                    TextEntry::make('setup_woocommerce')
                        ->label(__('Stappen voor WooCommerce'))
                        ->state(fn (?ResellerProfile $record) => $record ? new HtmlString(SetupInstructions::html($record, 'woocommerce')) : null)
                        ->html(),
                    TextEntry::make('setup_other')
                        ->label(__('Stappen voor een eigen koppeling'))
                        ->state(fn (?ResellerProfile $record) => $record ? new HtmlString(SetupInstructions::html($record, 'other')) : null)
                        ->html(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user.priceGroup', 'assortment', 'webhookSubscription']))
            ->columns([
                TextColumn::make('user.company')
                    ->label(__('Bedrijf'))
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label(__('E-mailadres'))
                    ->searchable(),
                TextColumn::make('assortment.name')
                    ->label(__('Assortiment'))
                    ->placeholder('-'),
                TextColumn::make('user.priceGroup.name')
                    ->label(__('Prijsgroep'))
                    ->placeholder(__('Geen prijsgroep'))
                    ->color(fn ($state) => $state ? null : 'warning'),
                IconColumn::make('enabled')
                    ->label(__('Toegang'))
                    ->boolean(),
                TextColumn::make('last_call')
                    ->label(__('Laatste aanroep'))
                    ->state(fn (ResellerProfile $record) => $record->apiLogs()->max('created_at'))
                    ->since()
                    ->placeholder(__('Nog nooit')),
                TextColumn::make('webhook')
                    ->label(__('Webhook'))
                    ->badge()
                    ->state(fn (ResellerProfile $record): string => self::webhookStatus($record))
                    ->color(fn (ResellerProfile $record): string => match (true) {
                        $record->webhookSubscription === null => 'gray',
                        $record->webhookSubscription->is_active => 'success',
                        default => 'danger',
                    }),
            ])
            ->recordActions([
                EditAction::make()->button(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TokensRelationManager::class,
            DeliveriesRelationManager::class,
            ApiLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResellers::route('/'),
            'create' => CreateReseller::route('/create'),
            'edit' => EditReseller::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function searchCustomers(string $search): array
    {
        $query = User::query()
            ->where('role', 'customer')
            ->whereDoesntHave('roles');

        return TokenizedSearch::apply($query, $search, ['first_name', 'last_name', 'email', 'company'])
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => self::customerLabel($user)])
            ->all();
    }

    public static function customerLabel(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        $name = trim((string) $user->name);

        return trim(($user->company ? $user->company . ', ' : '') . ($name !== '' ? $name . ' ' : '') . '(' . $user->email . ')');
    }

    private static function priceGroupInfo(mixed $userId): string
    {
        $user = $userId ? User::with('priceGroup')->find($userId) : null;

        if ($user === null) {
            return '-';
        }

        return $user->priceGroup?->name
            ?? __('Geen prijsgroep: deze afnemer krijgt de consumentenprijs als inkoopprijs. Stel een prijsgroep in bij Prijsgroepen.');
    }

    /**
     * De laatste keer dat Matrixify of WP All Import de feed daadwerkelijk
     * ophaalde, gelezen uit het API-logboek: dat is wat een afnemer echt
     * doet, in tegenstelling tot "laatst bijgewerkt" (wanneer wij het
     * bestand voor het laatst schreven).
     */
    private static function lastFeedFetch(?ResellerProfile $record): string
    {
        if ($record === null) {
            return '-';
        }

        $log = $record->apiLogs()
            ->where('path', 'like', '/reseller-feed/%')
            ->latest('created_at')
            ->first();

        return $log?->created_at?->diffForHumans() ?? __('Nog nooit');
    }

    private static function webhookStatus(?ResellerProfile $record): string
    {
        $subscription = $record?->webhookSubscription;

        return match (true) {
            $subscription === null => __('Niet ingesteld'),
            $subscription->is_active => __('Actief'),
            default => __('Uitgezet: :reden', ['reden' => (string) $subscription->disabled_reason]),
        };
    }
}
