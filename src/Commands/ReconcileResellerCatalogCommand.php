<?php

namespace Dashed\DashedEcommerceReseller\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;

class ReconcileResellerCatalogCommand extends Command
{
    protected $signature = 'reseller:reconcile {--user= : Alleen de afnemer met dit gebruikers-id}';

    protected $description = 'Stemt de catalogus van elke afnemer af op de huidige producten, prijzen en voorraad';

    public function handle(ResellerCatalogSync $sync): int
    {
        if ($userId = $this->option('user')) {
            $sync->syncUsers([(int) $userId]);
            $this->info("Afnemer {$userId} afgestemd.");

            return self::SUCCESS;
        }

        $count = $sync->reconcile();
        $this->info("{$count} afnemers afgestemd.");

        return self::SUCCESS;
    }
}
