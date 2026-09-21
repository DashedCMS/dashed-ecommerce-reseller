<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Http\ApiError;
use Dashed\DashedEcommerceReseller\Catalog\StockPresenter;
use Dashed\DashedEcommerceReseller\Http\Middleware\EnsureResellerAccess;

class StockController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'string', 'regex:/^\d+(,\d+)*$/']]);

        $ids = array_values(array_unique(array_map('intval', explode(',', (string) $request->query('ids')))));
        $max = (int) config('dashed-ecommerce-reseller.api.stock_ids_max', 250);

        if (count($ids) > $max) {
            return ApiError::response('invalid_parameters', "Maximaal {$max} ids per verzoek.", 422);
        }

        $profile = EnsureResellerAccess::profile($request);

        $products = $profile->assortment->productQuery()
            ->whereIn('dashed__products.id', $ids)
            ->orderBy('dashed__products.id')
            ->get();

        return response()->json([
            'data' => $products->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'stock' => StockPresenter::present($product, $profile->assortment),
            ])->values()->all(),
        ]);
    }
}
