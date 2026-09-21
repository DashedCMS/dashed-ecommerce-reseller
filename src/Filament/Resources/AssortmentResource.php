<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources;

use UnitEnum;
use BackedEnum;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Actions\DeleteAction;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Dashed\DashedEcommerceCore\Models\Product;
use Filament\Schemas\Components\Utilities\Get;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceReseller\Models\Assortment;
use Dashed\DashedEcommerceCore\Models\ProductCategory;
use Dashed\DashedEcommerceReseller\Enums\StockDisplay;
use Dashed\DashedCore\Classes\QueryHelpers\RelationshipSearchQuery;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages\EditAssortment;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages\ListAssortments;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages\CreateAssortment;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages\PreviewAssortment;

class AssortmentResource extends Resource
{
    protected static ?string $model = Assortment::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static string|UnitEnum|null $navigationGroup = 'Gebruikers';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('Assortimenten');
    }

    public static function getModelLabel(): string
    {
        return __('Assortiment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Assortimenten');
    }

    public static function form(Schema $schema): Schema
    {
        $isCapped = fn (Get $get): bool => $get('stock_display') === StockDisplay::Capped->value;

        return $schema->schema([
            Section::make(__('Assortiment'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('Naam'))
                        ->required()
                        ->maxLength(255),
                    Select::make('site_id')
                        ->label(__('Site'))
                        ->options(fn () => collect(Sites::getSites())->pluck('name', 'id')->all())
                        ->visible(fn () => Sites::getAmountOfSites() > 1),
                    Select::make('stock_display')
                        ->label(__('Voorraad tonen als'))
                        ->options(StockDisplay::options())
                        ->default(StockDisplay::Exact->value)
                        ->required()
                        ->live(),
                    TextInput::make('stock_cap')
                        ->label(__('Plafond'))
                        ->helperText(__('Meer voorraad dan dit ziet de afnemer als dit aantal.'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->visible($isCapped)
                        ->required($isCapped),
                ]),
            Section::make(__('Opnemen'))
                ->description(__('Een product zit in het assortiment als het in een van deze categorieen of productgroepen valt, of los is gekozen. Een categorie neemt alles eronder mee. Alleen publieke producten van de site tellen.'))
                ->columnSpanFull()
                ->schema([
                    self::ruleSelect('include_category', __('Categorieen'), ProductCategory::class),
                    self::ruleSelect('include_product_group', __('Productgroepen'), ProductGroup::class),
                    self::ruleSelect('include_product', __('Losse producten'), Product::class),
                ]),
            Section::make(__('Uitsluiten'))
                ->description(__('Uitsluiten wint altijd van opnemen.'))
                ->columnSpanFull()
                ->schema([
                    self::ruleSelect('exclude_category', __('Categorieen'), ProductCategory::class),
                    self::ruleSelect('exclude_product_group', __('Productgroepen'), ProductGroup::class),
                    self::ruleSelect('exclude_product', __('Losse producten'), Product::class),
                ]),
        ]);
    }

    /**
     * @param  class-string  $model
     */
    public static function ruleSelect(string $name, string $label, string $model): Select
    {
        return Select::make($name)
            ->label($label)
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => RelationshipSearchQuery::make($model, $search))
            ->getOptionLabelsUsing(fn (array $values): array => $model::query()
                ->whereIn('id', $values)
                ->get()
                ->mapWithKeys(fn ($record) => [$record->id => (string) $record->name])
                ->all());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Naam'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('site_id')
                    ->label(__('Site'))
                    ->visible(fn () => Sites::getAmountOfSites() > 1),
                TextColumn::make('stock_display')
                    ->label(__('Voorraad'))
                    ->badge()
                    ->formatStateUsing(fn (StockDisplay $state): string => $state->label()),
                TextColumn::make('products_count')
                    ->label(__('Producten'))
                    ->state(fn (Assortment $record): int => $record->productQuery()->count()),
                TextColumn::make('profiles_count')
                    ->label(__('Afnemers'))
                    ->counts('profiles'),
            ])
            ->recordActions([
                EditAction::make()->button(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssortments::route('/'),
            'create' => CreateAssortment::route('/create'),
            'edit' => EditAssortment::route('/{record}/edit'),
            'preview' => PreviewAssortment::route('/{record}/preview'),
        ];
    }
}
