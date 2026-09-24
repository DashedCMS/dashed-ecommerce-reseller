<?php

namespace Dashed\DashedEcommerceReseller\Feeds;

use Illuminate\Support\Collection;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\ProductFilterOption;
use Dashed\DashedEcommerceReseller\Models\CatalogItem;
use Dashed\DashedEcommerceReseller\Catalog\ContextScope;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Catalog\ProductPresenter;

/**
 * Wat een afnemer in zijn feed krijgt, los van het formaat. Prijzen,
 * voorraad en inhoud komen uit ProductPresenter, dus een feed zegt precies
 * hetzelfde als de API.
 *
 * Groepsregel: een product hoort bij zijn groep als die groep een filter voor
 * variaties heeft, ook met maar één variant in het assortiment. Zo verandert
 * een product niet van soort als er later een tweede variant bijkomt, en dat
 * zou in de winkel van de afnemer een dubbel product opleveren.
 */
class FeedCatalog
{
    /** Eén keer opgebouwd per catalogus: de vier formaten lezen dezelfde lijst. */
    private ?array $entries = null;

    private function __construct(
        private ResellerProfile $profile,
        private string $locale,
    ) {
    }

    public static function for(ResellerProfile $profile): self
    {
        $profile->loadMissing(['user', 'assortment.rules']);

        return new self($profile, $profile->locales()[0] ?? app()->getLocale());
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function siteName(): string
    {
        return (string) Customsetting::get('site_name', $this->profile->siteId());
    }

    /**
     * @return list<array{handle: string, group_id: ?int, name: string, variants: list<array{product: array, options: list<array{name: string, value: string}>}>}>
     */
    public function entries(): array
    {
        return $this->entries ??= $this->build();
    }

    private function build(): array
    {
        $presenter = app(ProductPresenter::class);
        $entries = [];

        // Alles hierbinnen draait in de site en taal van de afnemer, ook de
        // query zelf. Een geneste eager load (ProductFilter::$with laadt
        // altijd zijn opties mee) leest anders de omgevingssite in plaats
        // van die van de afnemer, en zonder actieve site geeft
        // ProductFilter::productFilterOptions() een harde fout op zijn
        // orderBy-kolom.
        ContextScope::run($this->profile->siteId(), $this->locale, function () use ($presenter, &$entries) {
            $this->profile->assortment->productQuery()
                ->with([...ProductPresenter::EAGER, 'productGroup.activeProductFiltersForVariations'])
                ->orderBy('dashed__products.id')
                ->chunkById(500, function (Collection $products) use ($presenter, &$entries) {
                    $options = $this->optionNames($products);

                    foreach ($products as $product) {
                        $presented = ProductPresenter::forLocale(
                            $presenter->present($product, $this->profile, [$this->locale]),
                            $this->locale,
                        );
                        $group = $product->productGroup;
                        $variationFilters = $group?->activeProductFiltersForVariations ?? collect();

                        if ($group === null || $variationFilters->isEmpty()) {
                            $entries['p'.$product->id] = [
                                'handle' => $presented['slug'] ?: 'product-'.$product->id,
                                'group_id' => null,
                                'name' => (string) $presented['name'],
                                'variants' => [['product' => $presented, 'options' => []]],
                            ];

                            continue;
                        }

                        $key = 'g'.$group->id;
                        $entries[$key] ??= [
                            'handle' => $this->groupHandle($group),
                            'group_id' => (int) $group->id,
                            'name' => (string) $group->name,
                            'variants' => [],
                        ];
                        $entries[$key]['variants'][] = [
                            'product' => $presented,
                            'options' => $this->variantOptions($product, $variationFilters, $options),
                        ];
                    }
                }, 'dashed__products.id', 'id');
        });

        return array_values($entries);
    }

    /**
     * Producten die de afnemer eerder zag en die uit zijn assortiment vielen,
     * zolang de bewaartermijn van de catalogus ze nog kent.
     *
     * @return list<array{handle: string, sku: ?string, whole_product: bool}>
     */
    public function removed(array $activeHandles): array
    {
        $ids = CatalogItem::query()
            ->where('user_id', $this->profile->user_id)
            ->whereNotNull('removed_at')
            ->pluck('product_id');

        // Product gebruikt SoftDeletes: een verwijderd product moet hier nog
        // gewoon terugkomen, anders verdwijnt de handle uit de feed zonder
        // dat de afnemer ooit een "removed"-regel voor die handle zag.
        // Zelfde reden als in entries(): binnen de site van de afnemer, want
        // een gegroepeerd product laadt via ProductFilter::$with ook zijn
        // opties mee.
        return ContextScope::run($this->profile->siteId(), $this->locale, fn () => Product::withTrashed()
            ->whereIn('id', $ids)
            ->with('productGroup.activeProductFiltersForVariations')
            ->orderBy('id')
            ->get()
            ->map(function (Product $product) use ($activeHandles) {
                $group = $product->productGroup;
                $grouped = $group !== null && $group->activeProductFiltersForVariations->isNotEmpty();
                $handle = $grouped
                    ? $this->groupHandle($group)
                    : ((string) $product->getTranslation('slug', $this->locale, false) ?: 'product-'.$product->id);

                return [
                    'handle' => $handle,
                    'sku' => $product->sku ?: null,
                    'whole_product' => ! in_array($handle, $activeHandles, true),
                ];
            })
            ->values()
            ->all());
    }

    private function groupHandle($group): string
    {
        return (string) $group->getTranslation('slug', $this->locale, false) ?: 'group-'.$group->id;
    }

    /**
     * @return Collection<int, string> optie-id => naam in de feedtaal
     */
    private function optionNames(Collection $products): Collection
    {
        $ids = $products->flatMap(fn (Product $p) => $p->productFilters->pluck('pivot.product_filter_option_id'))->filter()->unique();

        return ContextScope::run($this->profile->siteId(), $this->locale, fn () => ProductFilterOption::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn ($option) => [(int) $option->id => (string) $option->name]));
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private function variantOptions(Product $product, Collection $variationFilters, Collection $optionNames): array
    {
        return ContextScope::run($this->profile->siteId(), $this->locale, fn () => $variationFilters
            ->map(function ($filter) use ($product, $optionNames) {
                $chosen = $product->productFilters->firstWhere('id', $filter->id);
                $value = $chosen ? $optionNames->get((int) $chosen->pivot->product_filter_option_id) : null;

                return $value === null ? null : ['name' => (string) $filter->name, 'value' => $value];
            })
            ->filter()
            ->values()
            ->all());
    }
}
