<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Illuminate\Support\Facades\Cache;

/**
 * Wachtlijst van producten die opnieuw tegen de afnemerscatalogus moeten.
 * Elke opgeslagen variant zet een UpdateProductInformationJob voor zijn hele
 * groep klaar, dus een productgroep met twintig varianten opslaan gaf twintig
 * syncjobs over dezelfde twintig producten, die daarna ook nog op elkaars slot
 * stonden te wachten. Nu gaan de ids hierin en haalt één job ze in één keer op.
 */
class PendingProductSync
{
    private const KEY = 'dashed-reseller:pending-product-sync';

    /** @param list<int> $productIds */
    public static function add(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        self::locked(function () use ($productIds) {
            $pending = Cache::get(self::KEY, []);

            foreach ($productIds as $id) {
                $pending[(int) $id] = true;
            }

            // Ruim boven de vertraging van de job: blijft er door een
            // wegvallende worker toch iets liggen, dan vangt de nachtronde het.
            Cache::put(self::KEY, $pending, now()->addDay());
        });
    }

    /** @return list<int> */
    public static function take(): array
    {
        return self::locked(function () {
            $pending = Cache::pull(self::KEY, []);

            return array_map('intval', array_keys($pending));
        });
    }

    private static function locked(callable $callback): mixed
    {
        return Cache::lock(self::KEY.':lock', 10)->block(5, $callback);
    }
}
