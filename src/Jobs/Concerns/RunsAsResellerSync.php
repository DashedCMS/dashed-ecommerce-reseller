<?php

namespace Dashed\DashedEcommerceReseller\Jobs\Concerns;

use DateTimeInterface;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Alle afstemjobs delen één slot: twee jobs die tegelijk dezelfde
 * catalogusregel schrijven lopen anders tegen de unieke sleutel aan. Het
 * volume is klein genoeg om ze na elkaar te doen.
 */
trait RunsAsResellerSync
{
    public int $timeout = 1200;

    public int $maxExceptions = 3;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('dashed-reseller-catalog-sync'))->releaseAfter(15)->expireAfter(1800)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    protected function useResellerQueue(): void
    {
        $this->onQueue((string) config('dashed-ecommerce-reseller.sync.queue', 'ecommerce'));
    }
}
