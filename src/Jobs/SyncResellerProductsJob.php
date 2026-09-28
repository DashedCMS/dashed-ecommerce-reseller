<?php

namespace Dashed\DashedEcommerceReseller\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Dashed\DashedEcommerceReseller\Catalog\PendingProductSync;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;
use Dashed\DashedEcommerceReseller\Jobs\Concerns\RunsAsResellerSync;

/**
 * Verwerkt de wachtlijst van PendingProductSync. Uniek tot hij begint te
 * draaien en vertraagd, zodat een reeks opgeslagen producten één job wordt;
 * een wijziging die binnenkomt terwijl hij draait plant een nieuwe in.
 */
class SyncResellerProductsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsAsResellerSync;
    use SerializesModels;

    /**
     * Alleen nog gevuld bij jobs die van voor de wachtlijst in de wachtrij
     * staan.
     *
     * @param list<int> $productIds
     */
    public function __construct(public array $productIds = [])
    {
        $this->useResellerQueue();
    }

    public static function dispatchPending(): void
    {
        static::dispatch()->delay(now()->addSeconds((int) config('dashed-ecommerce-reseller.sync.debounce_seconds', 30)));
    }

    public function uniqueId(): string
    {
        return 'pending';
    }

    public function uniqueFor(): int
    {
        return (int) config('dashed-ecommerce-reseller.sync.debounce_seconds', 30) + 600;
    }

    public function handle(ResellerCatalogSync $sync): void
    {
        $productIds = array_values(array_unique([...$this->productIds, ...PendingProductSync::take()]));

        try {
            $sync->syncProducts($productIds);
        } catch (Throwable $e) {
            PendingProductSync::add($productIds);

            throw $e;
        }
    }
}
