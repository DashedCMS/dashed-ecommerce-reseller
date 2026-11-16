<?php

namespace Dashed\DashedEcommerceReseller\Commands;

use Throwable;
use Illuminate\Console\Command;
use Dashed\DashedCore\Models\BackgroundTask;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;
use Dashed\DashedEcommerceReseller\Jobs\GenerateResellerFeedsJob;

class ReconcileResellerCatalogCommand extends Command
{
    public const TASK_TYPE = 'reseller-reconcile';

    protected $signature = 'reseller:reconcile {--user= : Alleen de afnemer met dit gebruikers-id}';

    protected $description = 'Stemt de catalogus van elke afnemer af op de huidige producten, prijzen en voorraad';

    public function handle(ResellerCatalogSync $sync): int
    {
        $userId = $this->option('user');

        if ($userId) {
            $sync->syncUsers([(int) $userId]);
            $this->info("Afnemer {$userId} afgestemd.");
        } else {
            // De nachtronde staat in het takenicoon van dashed-core, met een
            // stap per afnemer. Het tellen loopt door rescue(): de
            // administratie mag de afstemming nooit tegenhouden.
            $task = class_exists(BackgroundTask::class)
                ? BackgroundTask::begin(
                    self::TASK_TYPE,
                    __('Catalogus van afnemers afstemmen'),
                    rescue(fn () => ResellerProfile::query()->active()->count(), null, report: false),
                    notify: false,
                )
                : null;

            try {
                $count = $sync->reconcile(fn () => $task?->advance());
            } catch (Throwable $e) {
                $task?->fail($e);

                throw $e;
            }

            $task?->finish(__(':aantal afnemers afgestemd.', ['aantal' => $count]));
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
