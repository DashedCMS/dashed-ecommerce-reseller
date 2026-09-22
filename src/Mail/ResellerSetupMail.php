<?php

namespace Dashed\DashedEcommerceReseller\Mail;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Mail\Concerns\HasEmailTemplate;
use Dashed\DashedCore\Mail\Contracts\ContainsSecrets;
use Dashed\DashedCore\Mail\Contracts\RegistersEmailTemplate;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Setup\SetupInstructions;
use Illuminate\Mail\Mailable;

/**
 * De mail waarmee een afnemer zijn koppeling instelt. Bevat de feedlinks en
 * soms een API-sleutel, dus ContainsSecrets: het logboek van verzonden mails
 * bewaart de body niet. Wordt met send() verstuurd en niet via de wachtrij,
 * zodat de sleutel ook niet in een queue-payload belandt.
 */
class ResellerSetupMail extends Mailable implements RegistersEmailTemplate, ContainsSecrets
{
    use HasEmailTemplate;

    public function __construct(
        public ResellerProfile $profile,
        public string $platform,
        public ?string $apiKey = null,
    ) {
    }

    public static function emailTemplateName(): string
    {
        return 'Afnemer koppelen (klant)';
    }

    public static function emailTemplateDescription(): ?string
    {
        return 'Uitleg voor een afnemer om ons assortiment in Shopify of WooCommerce te zetten, met zijn feedlinks. De stappen per platform komen uit de code.';
    }

    public static function availableVariables(): array
    {
        return ['customerFirstName', 'companyName', 'siteName', 'instructions', 'shopifyFeedUrl', 'woocommerceFeedUrl', 'apiDocsUrl', 'apiKey', 'primaryColor'];
    }

    public static function defaultSubject(): string
    {
        return 'Ons assortiment in je eigen webshop';
    }

    public static function defaultBlocks(): array
    {
        return [
            ['type' => 'text', 'data' => ['body' => '<p>Beste :customerFirstName:,</p><p>Je kunt het assortiment van :siteName: automatisch in je eigen webshop zetten. Prijzen en voorraad blijven daarna vanzelf bijgewerkt. Hieronder staat stap voor stap hoe dat gaat.</p>']],
            ['type' => 'text', 'data' => ['body' => ':instructions:']],
            ['type' => 'text', 'data' => ['body' => '<p>Kom je er niet uit? Beantwoord deze mail, dan helpen we je.</p>']],
        ];
    }

    /**
     * Altijd demodata, nooit context() van een echt profiel: het
     * mailsjablonenscherm laat elke beheerder met toegang tot mailsjablonen
     * de voorbeeldweergave zien, en die zou anders het echte feedtoken (en
     * eventueel een echte API-sleutel) van de eerste afnemer in de database
     * tonen. sampleData() mag dus nooit door een profiel heen lezen.
     */
    public static function sampleData(): array
    {
        return [
            'customerFirstName' => 'Jan',
            'companyName' => 'Demo BV',
            'siteName' => Customsetting::get('site_name'),
            'instructions' => '<ol><li>Matrixify installeren</li></ol>',
            'shopifyFeedUrl' => url('/reseller-feed/demo/shopify.csv'),
            'woocommerceFeedUrl' => url('/reseller-feed/demo/woocommerce.csv'),
            'apiDocsUrl' => url('/reseller-api/docs'),
            'apiKey' => '',
        ];
    }

    /**
     * Geen testverzending vanaf het mailsjablonenscherm: die zou met een
     * echt profiel ook een echte, geldige feedlink (en mogelijk een
     * API-sleutel) naar het opgegeven testadres sturen. Zonder een
     * makeForTest()-instantie kan het scherm niet testversturen.
     */
    public static function makeForTest(): ?self
    {
        return null;
    }

    public function context(): array
    {
        $user = $this->profile->user;
        $siteId = $this->profile->siteId();

        return [
            'customerFirstName' => e((string) $user?->first_name),
            'companyName' => e((string) $user?->company),
            'siteName' => e((string) Customsetting::get('site_name', $siteId)),
            'instructions' => SetupInstructions::html($this->profile, $this->platform, $this->apiKey),
            'shopifyFeedUrl' => e($this->profile->feedUrl('shopify')),
            'woocommerceFeedUrl' => e($this->profile->feedUrl('woocommerce')),
            'apiDocsUrl' => e(Sites::url(route('dashed.reseller-api.docs', absolute: false), $siteId)),
            'apiKey' => $this->apiKey !== null ? e($this->apiKey) : '',
        ];
    }

    public function build()
    {
        $locale = $this->profile->locales()[0] ?? app()->getLocale();
        $siteId = $this->profile->siteId();

        // De stappen (instructions) en het onderwerp bestaan uit __()-teksten,
        // dus die moeten in de taal van de site van de afnemer opgebouwd
        // worden, niet in de taal van de beheerder die op dat moment is
        // ingelogd. withLocale() (via de Localizable-trait op Mailable) zet
        // de applicatietaal om, bouwt alles binnen de closure, en zet hem
        // daarna weer terug, ook als er een exception valt.
        return $this->withLocale($locale, function () use ($locale, $siteId) {
            $context = $this->context();

            $html = $this->renderFromTemplate($context, $locale)
                ?? '<p>'.$context['customerFirstName'].'</p>'.$context['instructions'];

            [$fromEmail, $fromName] = $this->templateFrom(
                Customsetting::get('site_from_email', $siteId),
                Customsetting::get('site_name', $siteId),
                $locale,
            );

            return $this->html($html)
                ->from($fromEmail, $fromName)
                ->subject($this->templateSubject(__('Ons assortiment in je eigen webshop'), $context, $locale));
        });
    }
}
