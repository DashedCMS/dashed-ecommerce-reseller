<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Dashed\DashedEcommerceReseller\Models\ApiLog;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Jobs\GenerateResellerFeedsJob;

/**
 * De feeds voor Matrixify en WP All Import. Die sturen geen headers mee,
 * dus de sleutel zit in het pad. Onbekend en uitgezet geven allebei een 404:
 * de route zegt niets over welke sleutels bestaan.
 */
class FeedController
{
    public function __invoke(Request $request, string $token, string $format): Response
    {
        $started = hrtime(true);
        $profile = ResellerProfile::findByFeedToken($token);

        abort_unless(
            in_array($format, ResellerProfile::FEED_FORMATS, true) && $profile?->isActive(),
            404,
        );

        $disk = Storage::disk(config('dashed-ecommerce-reseller.feeds.disk', 'local'));
        $path = $profile->feedPath($format);

        if (! $disk->exists($path)) {
            // Genereren gebeurt bewust nooit in dit verzoek zelf. Het schrijven
            // kan mislukken (een grote catalogus, een tijdelijke storagefout),
            // en Laravel/Flare loggen een onafgevangen fout met de volledige
            // request-URL erbij, inclusief het rauwe feedtoken uit het pad,
            // naar een derde partij. Een queued job draagt geen request-URL
            // mee; de unique lock op het profiel voorkomt dubbele generaties
            // bij meerdere ophalingen kort na elkaar.
            //
            // Staat de wachtrijdriver op sync (lokaal, of een omgeving zonder
            // worker), dan draait een dispatch() gewoon synchroon in dit
            // verzoek en opent precies het lek dat hierboven vermeden wordt:
            // een fout in de generatie zou dan alsnog met de volledige
            // request-URL (en het rauwe token) naar Flare gaan. In dat geval
            // slaan we het dispatchen over; de feed wordt dan pas gemaakt
            // zodra er weer een echte queue-worker draait of iemand
            // handmatig genereert.
            if (config('queue.default') !== 'sync') {
                GenerateResellerFeedsJob::dispatch($profile->id);
            }

            rescue(fn () => ApiLog::create([
                'token_id' => null,
                'user_id' => $profile->user_id,
                'method' => 'GET',
                'path' => '/reseller-feed/***/'.$format.'.csv',
                'status' => 503,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'ip' => $request->ip(),
            ]), report: false);

            return response(
                'De feed wordt gegenereerd. Probeer het over een minuut opnieuw.',
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '60']
            );
        }

        // Cache-Control voorkomt dat de rand (Cloudflare rekent .csv
        // standaard tot de cachebare extensies) een respons met
        // inkoopprijzen bewaart en die aan een andere bezoeker teruggeeft.
        $response = $disk->download($path, 'products.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);

        // Nooit het token loggen: het pad in het logboek vervangt het altijd
        // door ***, ook al staat het verzoek zelf op de echte URL.
        rescue(fn () => ApiLog::create([
            'token_id' => null,
            'user_id' => $profile->user_id,
            'method' => 'GET',
            'path' => '/reseller-feed/***/'.$format.'.csv',
            'status' => 200,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'ip' => $request->ip(),
        ]), report: false);

        return $response;
    }
}
