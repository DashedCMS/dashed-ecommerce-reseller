<?php

namespace Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource\Pages;

use Filament\Tables\Table;
use Filament\Actions\Action;
use Dashed\DashedCore\Models\User;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Dashed\DashedEcommerceCore\Models\Product;
use Filament\Tables\Concerns\InteractsWithTable;
use Dashed\DashedEcommerceReseller\Catalog\StockPresenter;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Dashed\DashedEcommerceReseller\Catalog\ResellerPricing;
use Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource;

/**
 * Laat zien wat er naar buiten gaat voordat iemand een sleutel uitdeelt.
 */
class PreviewAssortment extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = AssortmentResource::class;

    protected string $view = 'dashed-ecommerce-reseller::filament.preview-assortment';

    public ?int $resellerId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->resellerId = $this->getRecord()->profiles()->value('user_id');
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Voorbeeld van :naam', ['naam' => $this->getRecord()->name]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('Bewerken'))
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->url(fn (): string => AssortmentResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function resellerOptions(): array
    {
        return $this->getRecord()->profiles()
            ->with('user')
            ->get()
            ->filter(fn ($profile) => $profile->user !== null)
            ->mapWithKeys(fn ($profile) => [$profile->user_id => $profile->webhookOwnerLabel()])
            ->all();
    }

    public function productCount(): int
    {
        return $this->getRecord()->productQuery()->count();
    }

    // De teksten voor de view staan hier, zodat de i18n-scanner ze ziet.
    public function labelPricesFor(): string
    {
        return __('Prijzen tonen voor');
    }

    public function labelNoReseller(): string
    {
        return __('Geen afnemer gekozen');
    }

    public function productCountLabel(): string
    {
        return __(':aantal producten in dit assortiment', ['aantal' => $this->productCount()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getRecord()->productQuery())
            ->columns([
                TextColumn::make('name')
                    ->label(__('Naam')),
                TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->placeholder('-'),
                TextColumn::make('ean')
                    ->label(__('EAN'))
                    ->placeholder('-'),
                IconColumn::make('matchable')
                    ->label(__('Te koppelen'))
                    ->tooltip(__('Shopify en WooCommerce koppelen op SKU of EAN. Zonder allebei wordt het product bij de afnemer dubbel aangemaakt.'))
                    ->state(fn (Product $record): bool => filled($record->sku) || filled($record->ean))
                    ->boolean(),
                TextColumn::make('stock_preview')
                    ->label(__('Voorraad voor de afnemer'))
                    ->state(fn (Product $record): string => $this->stockLabel($record)),
                TextColumn::make('price_preview')
                    ->label(__('Inkoopprijs ex btw'))
                    ->state(fn (Product $record): ?string => $this->priceLabel($record))
                    ->placeholder(__('Kies een afnemer')),
            ]);
    }

    private function stockLabel(Product $product): string
    {
        $stock = StockPresenter::present($product, $this->getRecord());

        return match (true) {
            $stock['unlimited'] => __('Onbeperkt'),
            $stock['quantity'] === null => $stock['in_stock'] ? __('Op voorraad') : __('Uitverkocht'),
            $stock['capped'] => $stock['quantity'] . '+',
            default => (string) $stock['quantity'],
        };
    }

    private function priceLabel(Product $product): ?string
    {
        $user = $this->resellerId ? User::find($this->resellerId) : null;

        if ($user === null) {
            return null;
        }

        return '€ ' . number_format(ResellerPricing::for($product, $user)['purchase_price'], 2, ',', '.');
    }
}
