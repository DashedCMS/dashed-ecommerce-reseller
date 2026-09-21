<?php

namespace Dashed\DashedEcommerceReseller\Webhooks;

use Dashed\DashedCore\Models\WebhookDelivery;
use Illuminate\Validation\ValidationException;
use Dashed\DashedCore\Models\WebhookSubscription;
use Dashed\DashedCore\Webhooks\Outgoing\UrlGuard;
use Dashed\DashedCore\Webhooks\Outgoing\WebhookDispatcher;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;

final class ResellerWebhooks
{
    public const EVENT_UPDATED = 'product.updated';
    public const EVENT_REMOVED = 'product.removed';
    public const EVENTS = [self::EVENT_UPDATED, self::EVENT_REMOVED];

    public static function register(): void
    {
        cms()->registerWebhookEvents('reseller', [
            self::EVENT_UPDATED => [
                'label' => __('Product gewijzigd of toegevoegd'),
                'description' => __('Een product in het assortiment van de afnemer is nieuw of veranderd: prijs, voorraad, tekst of afbeeldingen.'),
            ],
            self::EVENT_REMOVED => [
                'label' => __('Product uit het assortiment'),
                'description' => __('Een product is niet meer beschikbaar voor de afnemer.'),
            ],
        ]);
    }

    public static function configure(ResellerProfile $profile, ?string $url): ?WebhookSubscription
    {
        $url = trim((string) $url);
        $subscription = $profile->webhookSubscription;

        if ($url === '') {
            if ($subscription) {
                $profile->forceFill(['webhook_subscription_id' => null])->save();
                $subscription->delete();
            }

            return null;
        }

        if (($reason = UrlGuard::check($url)) !== null) {
            throw ValidationException::withMessages(['webhook_url' => $reason]);
        }

        if ($subscription) {
            $subscription->fill(['url' => $url, 'events' => self::EVENTS])->save();

            return $subscription;
        }

        $subscription = WebhookSubscription::create([
            'owner_type' => $profile->getMorphClass(),
            'owner_id' => $profile->getKey(),
            'url' => $url,
            'secret' => WebhookSubscription::generateSecret(),
            'events' => self::EVENTS,
            'is_active' => true,
        ]);

        $profile->forceFill(['webhook_subscription_id' => $subscription->id])->save();
        $profile->setRelation('webhookSubscription', $subscription);

        return $subscription;
    }

    public static function regenerateSecret(ResellerProfile $profile): string
    {
        $secret = WebhookSubscription::generateSecret();
        $profile->webhookSubscription->forceFill(['secret' => $secret])->save();

        return $secret;
    }

    public static function reactivate(ResellerProfile $profile): void
    {
        $profile->webhookSubscription?->forceFill([
            'is_active' => true,
            'consecutive_failures' => 0,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();
    }

    public static function ping(ResellerProfile $profile): ?WebhookDelivery
    {
        $subscription = $profile->webhookSubscription;

        if (! $subscription) {
            return null;
        }

        return app(WebhookDispatcher::class)->dispatch($subscription, 'ping', [
            'message' => 'ping',
            'reseller_id' => (int) $profile->user_id,
        ]);
    }

    public static function notify(ResellerProfile $profile, string $event, array $payload): void
    {
        $subscription = $profile->webhookSubscription;

        if ($subscription) {
            app(WebhookDispatcher::class)->dispatch($subscription, $event, $payload);
        }
    }
}
