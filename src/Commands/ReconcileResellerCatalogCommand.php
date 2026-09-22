<?php

namespace Dashed\DashedEcommerceReseller\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;
use Dashed\DashedEcommerceReseller\Jobs\GenerateResellerFeedsJob;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

class ReconcileResellerCatalogCommand extends Command
{
    protected $signature = 'reseller:reconcile {--user= : Alleen de afnemer met dit gebruikers-id}';

    protected $description = 'Stemt de catalogus van elke afnemer af op de huidige producten, prijzen en voorraad';

    public function handle(ResellerCatalogSync $sync): int
    {
        $userId = $this->option('user');

        if ($userId) {
            $sync->syncUsers([(int) $userId]);
            $this->info("Afnemer {$userId} afgestemd.");
        } else {
            $count = $sync->reconcile();
            $this->info("{$count} afnemers afgestemd.");
        }

        // Bewust voor elk actief profiel, ook zonder catalogusverandering:
        // zo trekt de nachtronde ook een feedbestand recht dat door een
        // gemiste synchronisatie achterloopt; de unieke lock op het profiel
        // in GenerateResellerFeedsJob voorkomt dat dit dubbel op de wachtrij
        // komt naast een dispatch die de synchronisatie zelf al deed.
        ResellerProfile::query()->active()
            ->when($userId, fn ($q) => $q->where('user_id', (int) $userId))
            ->each(fn (ResellerProfile $profile) => GenerateResellerFeedsJob::dispatchFor($profile));

        return self::SUCCESS;
    }
}
