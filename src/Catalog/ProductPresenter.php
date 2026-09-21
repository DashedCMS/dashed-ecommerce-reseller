<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceCore\Resources\ProductFeedResource;

/**
 * De weergave van een product voor één afnemer. Omschrijving, afbeeldingen
 * en kenmerken komen uit ProductFeedResource, zodat de afnemer dezelfde
 * inhoud krijgt als Channable; prijs en voorraad komen daar bewust niet
 * vandaan.
 */
class ProductPresenter
{
    public const EAGER = [
        'productGroup.activeProductFilters.productFilterOptions',
        'productFilters',
        'productCategories',
    ];

    /**
     * @param  list<string>  $locales
     */
    public function present(Product $product, ResellerProfile $profile, array $locales): array
    {
        $siteId = $profile->siteId();
        $firstLocale = $locales[0] ?? app()->getLocale();

        $data = ContextScope::run($siteId, $firstLocale, fn () => [
            'id' => (int) $product->id,
            'product_group_id' => $product->product_group_id ? (int) $product->product_group_id : null,
            'sku' => $product->sku ?: null,
            'ean' => $product->ean ?: null,
            ...ResellerPricing::for($product, $profile->user),
            'stock' => StockPresenter::present($product, $profile->assortment),
            'width' => self::number($product->width),
            'height' => self::number($product->height),
            'length' => self::number($product->length),
            'weight' => self::number($product->weight),
        ]);

        $data['translations'] = [];

        foreach ($locales as $locale) {
            $data['translations'][$locale] = ContextScope::run(
                $siteId,
                $locale,
                fn () => $this->translated($product, $siteId, $locale),
            );
        }

        return $data;
    }

    public static function forLocale(array $canonical, ?string $locale): array
    {
        if ($locale === null) {
            return $canonical;
        }

        $flat = $canonical;
        unset($flat['translations']);

        return array_merge($flat, $canonical['translations'][$locale] ?? []);
    }

    private function translated(Product $product, string $siteId, string $locale): array
    {
        $feed = (new ProductFeedResource($product))->toArray(null);

        return [
            'name' => (string) $product->name,
            'slug' => (string) $product->getTranslation('slug', $locale, false),
            'url' => Sites::url((string) $product->getUrl($locale), $siteId),
            'short_description' => $feed['short_description'] ?? null,
            'description' => $feed['description'] ?? null,
            'images' => array_values(array_filter((array) ($feed['images'] ?? []))),
            // sortBy('id'): productCategories is een kale belongsToMany zonder
            // orderBy, dus de databasevolgorde is niet gegarandeerd. Zonder
            // sortering flipt de fingerprint van dit product bij een reorder
            // zonder dat er zakelijk iets veranderd is.
            'categories' => $product->productCategories
                ->sortBy('id')
                ->values()
                ->map(fn ($category) => ['id' => (int) $category->id, 'name' => (string) $category->name])
                ->values()
                ->all(),
            'attributes' => (array) ($feed['attributes'] ?? []),
        ];
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
