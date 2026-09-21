<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Dashed\DashedCore\Models\User;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Classes\VatDisplay;

/**
 * Nooit de accessor current_price: die leest de ingelogde gebruiker en zijn
 * btw-weergave. priceForUser() met een expliciete gebruiker is de prijs die
 * deze afnemer betaalt, inclusief btw. De adviesprijs is de ruwe
 * consumentenprijs.
 */
final class ResellerPricing
{
    public static function for(Product $product, User $user): array
    {
        $vatRate = (float) ($product->vat_rate ?? 0);
        $incl = (float) $product->priceForUser($user);

        return [
            'purchase_price' => round(VatDisplay::exFromIncl($incl, $vatRate), 2),
            'purchase_price_incl' => round($incl, 2),
            'advice_price' => round((float) $product->getRawOriginal('current_price'), 2),
            'vat_rate' => $vatRate,
            'currency' => (string) config('dashed-ecommerce-reseller.currency', 'EUR'),
        ];
    }
}
