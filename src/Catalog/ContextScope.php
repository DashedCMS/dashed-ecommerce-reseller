<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Closure;
use Illuminate\Support\Arr;

/**
 * Vertaalde velden en URL's lezen de actieve taal en site. In een job of
 * een API-verzoek is dat niet vanzelf die van de afnemer, dus zetten we ze
 * tijdelijk en daarna altijd terug.
 */
final class ContextScope
{
    public static function run(string $siteId, string $locale, Closure $callback): mixed
    {
        // Was de sleutel er al vóór wij hem zetten? config()->has() zegt ook
        // true bij een aanwezige sleutel met waarde null, dus dat onderscheidt
        // "nooit gezet" niet van "expliciet null". We kijken daarom zelf in
        // de array.
        $sitePresent = array_key_exists('dashed_site_id', (array) config('dashed-core'));
        $previousSite = config('dashed-core.dashed_site_id');
        $previousLocale = app()->getLocale();

        config(['dashed-core.dashed_site_id' => $siteId]);
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            if ($sitePresent) {
                config(['dashed-core.dashed_site_id' => $previousSite]);
            } else {
                // Terugzetten op null zou de sleutel laten bestaan. Sites::getActive()
                // gebruikt config('dashed-core.dashed_site_id', $fallback), en die
                // fallback geldt bij Laravel alleen als de sleutel helemaal ontbreekt,
                // niet als hij aanwezig-maar-null is. Een achtergebleven null zou de
                // standaardsite dus blijvend uitschakelen voor de rest van het proces
                // (bijvoorbeeld elke volgende job in dezelfde queue-worker).
                config(['dashed-core' => Arr::except((array) config('dashed-core'), 'dashed_site_id')]);
            }
            app()->setLocale($previousLocale);
        }
    }
}
