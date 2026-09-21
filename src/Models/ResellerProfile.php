<?php

namespace Dashed\DashedEcommerceReseller\Models;

use Dashed\DashedCore\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedCore\Models\WebhookDelivery;
use Dashed\DashedCore\Classes\ApiTokenAbilities;
use Dashed\DashedCore\Models\WebhookSubscription;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Een klantaccount dat als afnemer de API mag gebruiken. Een aparte tabel en
 * geen kolommen op users: dit pakket is optioneel.
 */
class ResellerProfile extends Model
{
    protected $table = 'dashed__reseller_profiles';

    protected $fillable = ['user_id', 'assortment_id', 'enabled', 'webhook_subscription_id'];

    protected $attributes = ['enabled' => false];

    protected $casts = ['enabled' => 'boolean'];

    protected static function booted(): void
    {
        static::deleting(function (ResellerProfile $profile): void {
            $profile->revokeTokens();
            CatalogItem::query()->where('user_id', $profile->user_id)->delete();

            $subscription = $profile->webhookSubscription;

            if ($subscription) {
                $profile->forceFill(['webhook_subscription_id' => null])->saveQuietly();
                $subscription->delete();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assortment(): BelongsTo
    {
        return $this->belongsTo(Assortment::class);
    }

    public function webhookSubscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class);
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class, 'user_id', 'user_id');
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(ApiLog::class, 'user_id', 'user_id');
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id', 'webhook_subscription_id');
    }

    /**
     * Alleen de afnemerssleutels. Een app-sleutel van hetzelfde account hoort
     * hier niet bij. Het type staat erbij omdat ook een printer tokens heeft,
     * met ids die kunnen samenvallen met een gebruiker.
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'tokenable_id', 'user_id')
            ->whereIn('tokenable_type', self::userMorphTypes())
            ->where('abilities', 'like', '%"' . ApiTokenAbilities::RESELLER . '"%');
    }

    /**
     * @return list<string>
     */
    public static function userMorphTypes(): array
    {
        $configured = (string) config('auth.providers.users.model', User::class);

        return array_values(array_unique(array_filter([
            User::class,
            $configured,
            class_exists($configured) ? (new $configured())->getMorphClass() : null,
        ])));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->whereNotNull('assortment_id')
            ->whereHas('assortment')
            ->whereHas('user');
    }

    /**
     * Een account dat via het paneel moet inloggen is nooit afnemer, ook niet
     * met de schakelaar aan: een API-sleutel slaat MFA over.
     */
    public function isActive(): bool
    {
        return $this->enabled
            && $this->assortment !== null
            && $this->user !== null
            && ! $this->user->mustLoginViaPanel();
    }

    public function siteId(): string
    {
        return (string) $this->assortment->site_id;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return Sites::getLocales($this->siteId())->pluck('id')->values()->all();
    }

    public function createToken(string $name): NewAccessToken
    {
        return $this->user->createToken($name, [ApiTokenAbilities::RESELLER]);
    }

    public function revokeTokens(): int
    {
        return $this->tokens()->delete();
    }

    public function webhookOwnerLabel(): string
    {
        $user = $this->user;

        return trim((string) ($user?->company ?: $user?->name ?: __('Afnemer #:id', ['id' => $this->id])));
    }
}
