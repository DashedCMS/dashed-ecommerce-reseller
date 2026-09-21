<?php

namespace Dashed\DashedEcommerceReseller\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;
use Dashed\DashedEcommerceReseller\Jobs\Concerns\RunsAsResellerSync;

class SyncResellerProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsAsResellerSync;
    use SerializesModels;

    /** @param list<int> $productIds */
    public function __construct(public array $productIds)
    {
        $this->useResellerQueue();
    }

    public function handle(ResellerCatalogSync $sync): void
    {
        $sync->syncProducts($this->productIds);
    }
}
