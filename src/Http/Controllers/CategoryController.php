<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceReseller\Http\LocaleParam;
use Dashed\DashedEcommerceCore\Models\ProductCategory;
use Dashed\DashedEcommerceReseller\Catalog\ContextScope;
use Dashed\DashedEcommerceReseller\Http\Middleware\EnsureResellerAccess;

class CategoryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['locale' => ['sometimes', 'string']]);

        $profile = EnsureResellerAccess::profile($request);
        $locale = LocaleParam::resolve($request, $profile);

        $frontier = DB::table('dashed__product_category')
            ->whereIn('product_id', $profile->assortment->productQuery()->select('dashed__products.id'))
            ->distinct()
            ->pluck('product_category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Voorouders erbij, anders kan de afnemer de boom niet opbouwen.
        $categories = collect();

        while ($frontier !== []) {
            $found = ProductCategory::query()->whereIn('id', $frontier)->get();
            $categories = $categories->merge($found)->keyBy('id');
            $frontier = $found->pluck('parent_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $categories->has($id))
                ->unique()
                ->values()
                ->all();
        }

        $data = $categories->sortKeys()->values()->map(function (ProductCategory $category) use ($profile, $locale) {
            $base = [
                'id' => (int) $category->id,
                'parent_id' => $category->parent_id ? (int) $category->parent_id : null,
            ];

            $translated = fn (string $code) => ContextScope::run($profile->siteId(), $code, fn () => [
                'name' => (string) $category->name,
                'slug' => (string) $category->getTranslation('slug', $code, false),
            ]);

            if ($locale['single'] !== null) {
                return $base + $translated($locale['single']);
            }

            $base['translations'] = collect($locale['locales'])->mapWithKeys(fn (string $code) => [$code => $translated($code)])->all();

            return $base;
        })->all();

        return response()->json(['data' => $data]);
    }
}
