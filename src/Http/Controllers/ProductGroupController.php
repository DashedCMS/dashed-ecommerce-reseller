<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Http\Paging;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceReseller\Http\LocaleParam;
use Dashed\DashedEcommerceReseller\Models\CatalogItem;
use Dashed\DashedEcommerceReseller\Catalog\ContextScope;
use Dashed\DashedEcommerceCore\Models\ProductFilterOption;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Http\Middleware\EnsureResellerAccess;

/**
 * Shopify en WooCommerce willen een product met varianten, niet losse
 * producten. Een groep die hier niet meer staat is niet vanzelf weg: een
 * verwijderde variant meldt /products.
 */
class ProductGroupController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(Paging::rules() + [
            'updated_since' => ['sometimes', 'date'],
            'locale' => ['sometimes', 'string'],
        ]);

        $profile = EnsureResellerAccess::profile($request);
        $locale = LocaleParam::resolve($request, $profile);
        $assortment = $profile->assortment;

        $groups = ProductGroup::query()
            ->whereIn('id', $assortment->productQuery()->select('dashed__products.product_group_id'));

        if ($request->filled('updated_since')) {
            $since = Carbon::parse((string) $request->query('updated_since'))->setTimezone(config('app.timezone'));

            $groups->whereIn('id', Product::withTrashed()
                ->select('product_group_id')
                ->whereIn('id', CatalogItem::query()
                    ->select('product_id')
                    ->where('user_id', $profile->user_id)
                    ->where('changed_at', '>=', $since)));
        }

        [$page, $next] = Paging::page($groups, $request, 'id');

        $variants = $assortment->productQuery()
            ->whereIn('dashed__products.product_group_id', $page->modelKeys())
            ->with('productFilters')
            ->orderBy('dashed__products.id')
            ->get()
            ->groupBy('product_group_id');

        $allVariants = $variants->flatten(1);

        $options = ProductFilterOption::query()
            ->whereIn('id', $allVariants
                ->flatMap(fn (Product $product) => $product->productFilters->pluck('pivot.product_filter_option_id'))
                ->filter()
                ->unique())
            ->get()
            ->keyBy('id');

        $changedAt = CatalogItem::query()
            ->where('user_id', $profile->user_id)
            ->whereIn('product_id', $allVariants->pluck('id'))
            ->pluck('changed_at', 'product_id');

        $data = $page->map(fn (ProductGroup $group) => $this->render(
            $group,
            $variants->get($group->id, collect()),
            $options,
            $changedAt,
            $profile,
            $locale,
        ))->all();

        return response()->json(['data' => $data, 'meta' => Paging::meta($request, $next)]);
    }

    private function render(ProductGroup $group, Collection $variants, Collection $options, Collection $changedAt, ResellerProfile $profile, array $locale): array
    {
        $variantChanged = fn (Product $p) => isset($changedAt[$p->id]) ? Carbon::parse($changedAt[$p->id])->toIso8601String() : null;
        $groupChanged = $variants->map($variantChanged)->filter()->max();

        $optionPairs = fn (Product $p) => $p->productFilters
            ->map(fn ($filter) => ['filter' => $filter, 'option' => $options->get((int) $filter->pivot->product_filter_option_id)])
            ->filter(fn (array $pair) => $pair['option'] !== null)
            ->values();

        if ($locale['single'] !== null) {
            return ContextScope::run($profile->siteId(), $locale['single'], fn () => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'changed_at' => $groupChanged,
                'variants' => $variants->map(fn (Product $p) => [
                    'product_id' => (int) $p->id,
                    'sku' => $p->sku ?: null,
                    'ean' => $p->ean ?: null,
                    'changed_at' => $variantChanged($p),
                    'options' => $optionPairs($p)->map(fn (array $pair) => [
                        'filter_id' => (int) $pair['filter']->id,
                        'filter' => (string) $pair['filter']->name,
                        'option_id' => (int) $pair['option']->id,
                        'value' => (string) $pair['option']->name,
                    ])->all(),
                ])->values()->all(),
            ]);
        }

        $translations = [];

        foreach ($locale['locales'] as $code) {
            $translations[$code] = ContextScope::run($profile->siteId(), $code, function () use ($group, $variants, $optionPairs) {
                $pairs = $variants->flatMap(fn (Product $p) => $optionPairs($p));

                return [
                    'name' => (string) $group->name,
                    'filters' => $pairs->mapWithKeys(fn (array $pair) => [(string) $pair['filter']->id => (string) $pair['filter']->name])->all(),
                    'options' => $pairs->mapWithKeys(fn (array $pair) => [(string) $pair['option']->id => (string) $pair['option']->name])->all(),
                ];
            });
        }

        return [
            'id' => (int) $group->id,
            'changed_at' => $groupChanged,
            'variants' => $variants->map(fn (Product $p) => [
                'product_id' => (int) $p->id,
                'sku' => $p->sku ?: null,
                'ean' => $p->ean ?: null,
                'changed_at' => $variantChanged($p),
                'options' => $optionPairs($p)->map(fn (array $pair) => [
                    'filter_id' => (int) $pair['filter']->id,
                    'option_id' => (int) $pair['option']->id,
                ])->all(),
            ])->values()->all(),
            'translations' => $translations,
        ];
    }
}
