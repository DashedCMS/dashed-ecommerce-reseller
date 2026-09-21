<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Http\Paging;
use Dashed\DashedEcommerceReseller\Http\ApiError;
use Dashed\DashedEcommerceReseller\Http\LocaleParam;
use Dashed\DashedEcommerceReseller\Models\CatalogItem;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Catalog\ProductPresenter;
use Dashed\DashedEcommerceReseller\Http\Middleware\EnsureResellerAccess;

class ProductController
{
    public function __construct(private ProductPresenter $presenter)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(Paging::rules() + [
            'updated_since' => ['sometimes', 'date'],
            'locale' => ['sometimes', 'string'],
        ]);

        $profile = EnsureResellerAccess::profile($request);
        $locale = LocaleParam::resolve($request, $profile);

        if ($request->filled('updated_since')) {
            return $this->changes($request, $profile, $locale);
        }

        [$products, $next] = Paging::page(
            $profile->assortment->productQuery()->with(ProductPresenter::EAGER),
            $request,
            'dashed__products.id',
        );

        $changedAt = CatalogItem::query()
            ->where('user_id', $profile->user_id)
            ->whereIn('product_id', $products->modelKeys())
            ->pluck('changed_at', 'product_id');

        return response()->json([
            'data' => $products
                ->map(fn (Product $product) => $this->render($product, $profile, $locale, $changedAt[$product->id] ?? null))
                ->all(),
            'meta' => Paging::meta($request, $next),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $request->validate(['locale' => ['sometimes', 'string']]);

        $profile = EnsureResellerAccess::profile($request);
        $locale = LocaleParam::resolve($request, $profile);

        $product = $profile->assortment->productQuery()
            ->with(ProductPresenter::EAGER)
            ->where('dashed__products.id', (int) $id)
            ->first();

        if ($product === null) {
            return ApiError::response('not_found', 'Niet gevonden.', 404);
        }

        $changedAt = CatalogItem::query()
            ->where('user_id', $profile->user_id)
            ->where('product_id', $product->id)
            ->value('changed_at');

        return response()->json(['data' => $this->render($product, $profile, $locale, $changedAt)]);
    }

    /**
     * Bladert over de catalogusregels, niet over de producten: alleen daar
     * staan de verwijderde producten nog.
     */
    private function changes(Request $request, ResellerProfile $profile, array $locale): JsonResponse
    {
        $since = Carbon::parse((string) $request->query('updated_since'))->setTimezone(config('app.timezone'));

        [$items, $next] = Paging::page(
            CatalogItem::query()->where('user_id', $profile->user_id)->where('changed_at', '>=', $since),
            $request,
            'id',
        );

        $products = $profile->assortment->productQuery()
            ->whereIn('dashed__products.id', $items->pluck('product_id'))
            ->with(ProductPresenter::EAGER)
            ->get()
            ->keyBy('id');

        $data = $items->map(function (CatalogItem $item) use ($products, $profile, $locale) {
            $product = $item->removed_at === null ? $products->get($item->product_id) : null;

            if ($product === null) {
                return [
                    'id' => (int) $item->product_id,
                    'removed' => true,
                    'changed_at' => $item->changed_at->toIso8601String(),
                ];
            }

            return $this->render($product, $profile, $locale, $item->changed_at);
        })->all();

        return response()->json(['data' => $data, 'meta' => Paging::meta($request, $next)]);
    }

    private function render(Product $product, ResellerProfile $profile, array $locale, mixed $changedAt): array
    {
        $canonical = $this->presenter->present($product, $profile, $locale['locales']);
        $canonical['removed'] = false;
        $canonical['changed_at'] = $changedAt ? Carbon::parse($changedAt)->toIso8601String() : null;

        return ProductPresenter::forLocale($canonical, $locale['single']);
    }
}
