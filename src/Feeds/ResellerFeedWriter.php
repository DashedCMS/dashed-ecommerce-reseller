<?php

namespace Dashed\DashedEcommerceReseller\Feeds;

use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

/**
 * Schrijft eerst naar een tijdelijk bestand en hernoemt dat daarna: een
 * ophaling tijdens het schrijven krijgt zo altijd het vorige, volledige
 * bestand en nooit een half.
 */
class ResellerFeedWriter
{
    public function write(ResellerProfile $profile): void
    {
        // Eén catalogus voor alle formaten. Het opbouwen is het dure deel
        // (per product prijs, voorraad en kenmerken), en per formaat opnieuw
        // doen betekende vier keer hetzelfde werk binnen de timeout van
        // GenerateResellerFeedsJob.
        $catalog = FeedCatalog::for($profile);

        foreach (ResellerProfile::FEED_FORMATS as $format) {
            $this->writeFormat($profile, $format, $catalog);
        }
    }

    public function writeFormat(ResellerProfile $profile, string $format, ?FeedCatalog $catalog = null): void
    {
        $catalog ??= FeedCatalog::for($profile);

        // JSON en XML worden in hun geheel opgebouwd en niet regel voor regel
        // gestreamd zoals een CSV: het zijn bomen, en een half geschreven boom
        // is geen geldig document. Ze gaan verder langs precies hetzelfde
        // schrijfpatroon, dus ook zij verschijnen atomisch.
        if (in_array($format, ['json', 'xml'], true)) {
            $feed = new GenericFeed($catalog);

            $this->put($profile, $format, $format === 'json' ? $feed->json() : $feed->xml());

            return;
        }

        [$header, $rows] = match ($format) {
            'shopify' => [ShopifyFeed::HEADER, ShopifyFeed::rows($catalog)],
            'woocommerce' => (function () use ($catalog) {
                $feed = new WooCommerceFeed($catalog);

                return [$feed->header(), $feed->rows()];
            })(),
            default => throw new RuntimeException("Onbekend feedformaat: {$format}"),
        };

        $disk = Storage::disk(config('dashed-ecommerce-reseller.feeds.disk', 'local'));
        $path = $profile->feedPath($format);
        // Een vaste tijdelijke naam botst zodra twee runs voor hetzelfde
        // profiel overlappen: de unieke lock van GenerateResellerFeedsJob
        // gaat al open zodra de job begint te draaien (ShouldBeUniqueUntilProcessing),
        // dus een nieuwe aanvraag kan al aan een tweede run beginnen terwijl
        // de eerste nog schrijft. Twee processen op hetzelfde .tmp-bestand
        // zouden elkaars inhoud kunnen doorelkaar husselen, en dat is precies
        // het gescheurde bestand dat dit schrijfpatroon moet voorkomen. Een
        // willekeurige component per run maakt het tijdelijke bestand uniek.
        $tmp = $path.'.'.Str::random(8).'.tmp';

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);

        try {
            $disk->put($tmp, $handle);

            // Geen delete() vooraf: move() op de lokale schijf is een rename(),
            // en die verwisselt een bestaand doelbestand atomisch. Een delete()
            // ervoor zou juist het gat openzetten dat dit schrijfpatroon
            // probeert te vermijden.
            $disk->move($tmp, $path);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            // Bij een mislukte move() (bijvoorbeeld een storagefout) blijft
            // het tijdelijke bestand anders voorgoed op schijf staan; het
            // eindresultaat ($path) is dan nog steeds het vorige, volledige
            // bestand.
            if ($disk->exists($tmp)) {
                $disk->delete($tmp);
            }
        }
    }

    /**
     * Schrijven via een uniek tijdelijk bestand en daarna hernoemen, om
     * dezelfde reden als hierboven: een ophaling tijdens het schrijven krijgt
     * het vorige, volledige bestand en nooit een half.
     */
    private function put(ResellerProfile $profile, string $format, string $inhoud): void
    {
        $disk = Storage::disk(config('dashed-ecommerce-reseller.feeds.disk', 'local'));
        $path = $profile->feedPath($format);
        $tmp = $path.'.'.Str::random(8).'.tmp';

        try {
            $disk->put($tmp, $inhoud);
            $disk->move($tmp, $path);
        } finally {
            if ($disk->exists($tmp)) {
                $disk->delete($tmp);
            }
        }
    }
}
