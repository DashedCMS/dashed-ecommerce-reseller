<?php

namespace Dashed\DashedEcommerceReseller\Setup;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

/**
 * De stappen om een feed in te stellen, in gewone taal. De mail en de
 * afnemerpagina in het CMS lezen allebei hier, zodat wat de beheerder
 * aan de telefoon voorleest hetzelfde is als wat de afnemer in zijn mail
 * heeft.
 */
final class SetupInstructions
{
    public const PLATFORMS = ['shopify', 'woocommerce', 'other'];

    /**
     * @return array<string, string>
     */
    public static function platformOptions(): array
    {
        return [
            'shopify' => __('Shopify'),
            'woocommerce' => __('WooCommerce'),
            'other' => __('Anders / eigen developer'),
        ];
    }

    public static function html(ResellerProfile $profile, string $platform, ?string $apiKey = null): string
    {
        return match ($platform) {
            'shopify' => self::shopify($profile),
            'woocommerce' => self::woocommerce($profile),
            default => self::other($profile, $apiKey),
        }.self::footer();
    }

    private static function shopify(ResellerProfile $profile): string
    {
        return self::steps(__('Zo zet je ons assortiment in je Shopify-winkel'), [
            __('Installeer de app Matrixify uit de Shopify App Store. Je hebt minimaal het Basic-abonnement nodig (ongeveer $20 per maand); daarmee kan Matrixify de producten automatisch blijven bijwerken.'),
            __('Ga in Matrixify naar Import en kies bij de bron "From URL".'),
            __('Plak deze link: :url', ['url' => '<code>'.e($profile->feedUrl('shopify')).'</code>']),
            __('Zet bij "Schedule" een herhaling, bijvoorbeeld elke 4 uur. Dan kloppen prijzen en voorraad in je winkel steeds met die van ons.'),
            __('Klik op Import. De eerste keer zet Matrixify alle producten in je winkel; daarna werkt hij alleen bij wat veranderd is.'),
            __('Hernoemen wij een product bij ons, dan verandert de link (Handle) ernaartoe en maakt Matrixify een nieuw product aan; het oude blijft dan naast het nieuwe staan.'),
        ]).self::prices();
    }

    private static function woocommerce(ResellerProfile $profile): string
    {
        return self::steps(__('Zo zet je ons assortiment in je WooCommerce-winkel'), [
            __('Installeer de plugin WP All Import Pro met de WooCommerce-add-on. Je hebt het Professional-pakket nodig (ongeveer $299 per jaar); de gratis versie kan geen link inlezen.'),
            __('Ga naar All Import, Settings, en zet "File Download Time Limit" op 60 seconden.'),
            __('Kies All Import, New Import, "Download a file", en plak deze link: :url', ['url' => '<code>'.e($profile->feedUrl('woocommerce')).'</code>']),
            __('Kies "WooCommerce Products" en koppel de kolommen. Bij variaties kies je "Linking multiple variations together" op de kolom Parent SKU.'),
            __('Kies bij "unique identifier" de kolom SKU, zodat een product bij de volgende import wordt bijgewerkt en niet dubbel aangemaakt.'),
            __('Zet "Remove or modify records not present in this import file" aan, alleen voor deze import, en kies "Change post status to draft". Zo verdwijnt een product dat wij niet meer leveren uit je winkel zonder dat je werk eraan weg is.'),
            __('Stel bij Scheduling een herhaling in, bijvoorbeeld elke 4 uur.'),
        ]).self::prices();
    }

    private static function other(ResellerProfile $profile, ?string $apiKey): string
    {
        $items = [
            __('De volledige API staat op :url. Stuur deze link naar je developer of koppelpartij.', ['url' => '<a href="'.e(Sites::url(route('dashed.reseller-api.docs', absolute: false), $profile->assortment?->site_id)).'">'.e(__('de documentatiepagina')).'</a>']),
            __('Kant-en-klare productbestanden, voor wie liever een bestand inleest: Shopify (Matrixify) :shopify en WooCommerce (WP All Import) :woocommerce', [
                'shopify' => '<code>'.e($profile->feedUrl('shopify')).'</code>',
                'woocommerce' => '<code>'.e($profile->feedUrl('woocommerce')).'</code>',
            ]),
        ];

        if ($apiKey !== null) {
            $items[] = __('Je API-sleutel: :key. Bewaar hem goed, we kunnen hem niet opnieuw tonen.', ['key' => '<code>'.e($apiKey).'</code>']);
        }

        return self::steps(__('Koppelen via onze API'), $items);
    }

    private static function prices(): string
    {
        return '<p>'.e(__('In het bestand staat onze adviesprijs inclusief btw als verkoopprijs en jouw inkoopprijs exclusief btw als kostprijs. De verkoopprijs kun je daarna in je eigen winkel aanpassen.')).'</p>';
    }

    private static function footer(): string
    {
        return '<p>'.e(__('Deel deze links met niemand: wie ze heeft, ziet jouw inkoopprijzen. Denk je dat een link is uitgelekt, laat het ons weten, dan maken we een nieuwe.')).'</p>';
    }

    /**
     * @param  list<string>  $items  HTML; wat van buiten komt is al ge-escaped
     */
    private static function steps(string $title, array $items): string
    {
        return '<p><strong>'.e($title).'</strong></p><ol>'
            .implode('', array_map(fn (string $item) => '<li>'.$item.'</li>', $items))
            .'</ol>';
    }
}
