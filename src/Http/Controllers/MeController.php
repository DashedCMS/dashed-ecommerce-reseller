<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Dashed\DashedEcommerceReseller\Http\Middleware\EnsureResellerAccess;

class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $profile = EnsureResellerAccess::profile($request);
        $user = $profile->user;
        $locales = $profile->locales();

        return response()->json(['data' => [
            'id' => (int) $user->getKey(),
            'company' => $user->company ?: null,
            'email' => $user->email,
            'assortment' => [
                'id' => (int) $profile->assortment->id,
                'name' => $profile->assortment->name,
            ],
            'stock_display' => $profile->assortment->stock_display->value,
            'locales' => $locales,
            'default_locale' => $locales[0] ?? null,
            'currency' => (string) config('dashed-ecommerce-reseller.currency', 'EUR'),
            'prices_from_price_group' => (bool) $user->price_group_id,
            'webhooks' => (bool) $profile->webhookSubscription?->is_active,
            'server_time' => now()->toIso8601String(),
        ]]);
    }
}
