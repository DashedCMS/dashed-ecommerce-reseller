<?php

namespace Dashed\DashedEcommerceReseller\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;
use Dashed\DashedEcommerceReseller\Jobs\Concerns\RunsAsResellerSync;

class SyncResellerPriceGroupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsAsResellerSync;
    use SerializesModels;

    public function __construct(public int $priceGroupId)
    {
        $this->useResellerQueue();
    }

    public function handle(ResellerCatalogSync $sync): void
    {
        $sync->syncPriceGroup($this->priceGroupId);
    }
}
