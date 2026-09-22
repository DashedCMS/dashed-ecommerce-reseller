<?php

namespace Dashed\DashedEcommerceReseller\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Dashed\DashedEcommerceReseller\Feeds\ResellerFeedWriter;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

/**
 * Uniek per profiel en vertraagd: een import van honderd producten levert
 * honderd synchronisaties op, en dat moet één nieuw bestand worden. Zolang
 * deze job wacht, worden nieuwe aanvragen voor hetzelfde profiel genegeerd.
 * ShouldBeUniqueUntilProcessing in plaats van ShouldBeUnique: het slot gaat
 * al open zodra de job begint te draaien, niet pas als hij klaar is. Een
 * wijziging die binnenkomt terwijl deze job nog aan het schrijven is, plant
 * zo alsnog een nieuwe run in plaats van stilzwijgend te verdwijnen.
 */
class GenerateResellerFeedsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $profileId)
    {
        $this->onQueue(config('dashed-ecommerce-reseller.sync.queue', 'ecommerce'));
    }

    public static function dispatchFor(ResellerProfile $profile): void
    {
        static::dispatch($profile->id)->delay(now()->addSeconds((int) config('dashed-ecommerce-reseller.feeds.delay_seconds', 300)));
    }

    public function uniqueId(): string
    {
        return (string) $this->profileId;
    }

    public function uniqueFor(): int
    {
        return (int) config('dashed-ecommerce-reseller.feeds.delay_seconds', 300) + 600;
    }

    public function handle(ResellerFeedWriter $writer): void
    {
        $profile = ResellerProfile::query()->with(['user', 'assortment.rules'])->find($this->profileId);

        if ($profile === null || ! $profile->isActive()) {
            return;
        }

        $writer->write($profile);
    }
}
