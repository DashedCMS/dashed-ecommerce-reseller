<?php

namespace Dashed\DashedEcommerceReseller\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Contracts\View\View;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Models\Customsetting;

/**
 * Publiek: de mail aan een afnemer linkt hierheen. Er staat niets geheims
 * in; de sleutel krijgt de afnemer apart.
 */
class ApiDocsController
{
    public function __invoke(): View
    {
        $path = __DIR__.'/../../../resources/docs/reseller-api.md';
        $domain = parse_url(Sites::url('/'), PHP_URL_HOST) ?: request()->getHost();

        // Str::markdown() parset het hele document opnieuw bij elke aanvraag;
        // met een verzoeklimiet van 60 per minuut is dat op zichzelf geen
        // probleem, maar de omzetting hoeft niet vaker dan één keer per uur
        // per domein, en zeker niet opnieuw zolang het bronbestand niet is
        // gewijzigd. De sleutel bevat daarom de mtime van het bestand (een
        // release met een aangepaste pagina verdringt zo vanzelf de oude
        // cache) en het domein (de <domein>-vervanging verschilt per site).
        $html = Cache::remember(
            'dashed-ecommerce-reseller.api-docs.'.filemtime($path).'.'.$domain,
            3600,
            fn () => Str::markdown(str_replace('<domein>', $domain, file_get_contents($path)), ['html_input' => 'strip']),
        );

        return view('dashed-ecommerce-reseller::api-docs', [
            'siteName' => (string) Customsetting::get('site_name'),
            'html' => $html,
        ]);
    }
}
