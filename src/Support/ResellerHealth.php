<?php

namespace Dashed\DashedEcommerceReseller\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Models\WebhookSubscription;
use Dashed\DashedCore\Integrations\IntegrationHealth;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Catalog\ResellerCatalogSync;

final class ResellerHealth
{
    public static function check(?string $siteId = null): IntegrationHealth
    {
        $profiles = ResellerProfile::query()->active()->get();

        if ($profiles->isEmpty()) {
            return IntegrationHealth::disabled();
        }

        $last = Cache::get(ResellerCatalogSync::LAST_RECONCILE_KEY);
        $lastAt = $last ? Carbon::parse($last) : null;

        if ($lastAt === null || $lastAt->lt(now()->subHours(26))) {
            return IntegrationHealth::failing(__('De nachtelijke afstemming van de afnemerscatalogus heeft de afgelopen 26 uur niet gedraaid. Controleer de scheduler.'), $lastAt);
        }

        // Via de morph-eigenaar en niet via ResellerProfile::webhookSubscription()
        // (belongsTo op webhook_subscription_id): dat is de kolom die
        // ResellerWebhooks::configure() erbij zet, maar de bron van waarheid voor
        // "wie bezit dit abonnement" is de morph op WebhookSubscription zelf.
        $disabled = WebhookSubscription::query()
            ->where('owner_type', (new ResellerProfile())->getMorphClass())
            ->whereIn('owner_id', $profiles->pluck('id'))
            ->where('is_active', false)
            ->count();

        if ($disabled > 0) {
            return IntegrationHealth::failing(__(':aantal webhooks van afnemers staan uit na te veel mislukte pogingen.', ['aantal' => $disabled]), $lastAt);
        }

        return IntegrationHealth::ok($lastAt);
    }
}
