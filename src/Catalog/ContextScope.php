<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Closure;

/**
 * Vertaalde velden en URL's lezen de actieve taal en site. In een job of
 * een API-verzoek is dat niet vanzelf die van de afnemer, dus zetten we ze
 * tijdelijk en daarna altijd terug.
 */
final class ContextScope
{
    public static function run(string $siteId, string $locale, Closure $callback): mixed
    {
        $previousSite = config('dashed-core.dashed_site_id');
        $previousLocale = app()->getLocale();

        config(['dashed-core.dashed_site_id' => $siteId]);
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            config(['dashed-core.dashed_site_id' => $previousSite]);
            app()->setLocale($previousLocale);
        }
    }
}
