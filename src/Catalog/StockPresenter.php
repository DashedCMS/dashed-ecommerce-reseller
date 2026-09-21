<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Models\Assortment;
use Dashed\DashedEcommerceReseller\Enums\StockDisplay;

/**
 * total_stock en niet directSellableStock(): die laatste trekt
 * winkelwagenreserveringen af, en die veranderen zonder event. De
 * vingerafdruk zou dan steeds afwijken van wat de API laat zien.
 * calculateStock() zet 100000 neer voor alles wat onbeperkt verkoopbaar is.
 */
final class StockPresenter
{
    public const UNLIMITED = 100000;

    public static function present(Product $product, Assortment $assortment): array
    {
        $raw = (int) ($product->total_stock ?? 0);
        $unlimited = $raw >= self::UNLIMITED;
        $quantity = $unlimited ? null : max(0, $raw);
        $inStock = (bool) $product->in_stock;
        $mode = $assortment->stock_display ?? StockDisplay::Exact;
        $capped = false;

        if ($mode === StockDisplay::Status) {
            $quantity = null;
            $unlimited = false;
        } elseif ($mode === StockDisplay::Capped) {
            $cap = max(1, (int) $assortment->stock_cap);

            // Ook onbeperkt wordt het plafond: "onbeperkt" zegt meer dan de
            // beheerder met een plafond wilde laten zien.
            if ($unlimited || $quantity > $cap) {
                $quantity = $cap;
                $capped = true;
                $unlimited = false;
            }
        }

        return [
            'mode' => $mode->value,
            'quantity' => $quantity,
            'capped' => $capped,
            'unlimited' => $unlimited,
            'in_stock' => $inStock,
            'expected_in_stock_date' => $inStock ? null : $product->expected_in_stock_date?->toDateString(),
        ];
    }
}
