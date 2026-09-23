<?php

namespace Dashed\DashedEcommerceReseller\Models;

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Dashed\DashedCore\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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

    public const FEED_FORMATS = ['shopify', 'woocommerce', 'json', 'xml'];

    /**
     * De bestandsnaam per formaat. Een vaste afleiding uit de formaatnaam kan
     * niet: de twee importfeeds heten naar hun platform en zijn CSV, de twee
     * open feeds heten naar hun inhoud. De naam staat in de URL van de
     * afnemer, dus hij ligt hiermee op een plek vast in plaats van verspreid
     * over route, controller en model.
     *
     * @var array<string, string>
     */
    public const FEED_FILES = [
        'shopify' => 'shopify.csv',
        'woocommerce' => 'woocommerce.csv',
        'json' => 'products.json',
        'xml' => 'products.xml',
    ];

    protected $fillable = ['user_id', 'assortment_id', 'enabled', 'webhook_subscription_id'];

    protected $attributes = ['enabled' => false];

    protected $casts = ['enabled' => 'boolean', 'feed_token' => 'encrypted'];

    protected $hidden = ['feed_token', 'feed_token_hash'];

    protected static function booted(): void
    {
        static::creating(function (ResellerProfile $profile): void {
            if (blank($profile->feed_token_hash)) {
                $profile->fillFeedToken(Str::random(40));
            }
        });

        static::deleting(function (ResellerProfile $profile): void {
            $profile->revokeTokens();
            CatalogItem::query()->where('user_id', $profile->user_id)->delete();

            $subscription = $profile->webhookSubscription;

            if ($subscription) {
                $profile->forceFill(['webhook_subscription_id' => null])->saveQuietly();
                $subscription->delete();
            }

            // Feedbestanden staan per profiel-id, dus zonder opruiming
            // blijven ze na verwijderen van het profiel permanent op schijf
            // staan: er bestaat geen bewaartermijn of andere opruimronde die
            // ze nog vindt. rescue(): een storagefout hier mag het
            // verwijderen van het profiel zelf niet blokkeren.
            rescue(fn () => Storage::disk(config('dashed-ecommerce-reseller.feeds.disk', 'local'))
                ->deleteDirectory('reseller-feeds/'.$profile->id), report: false);
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

    public function regenerateFeedToken(): string
    {
        $token = Str::random(40);
        $this->fillFeedToken($token);
        $this->save();

        return $token;
    }

    private function fillFeedToken(string $token): void
    {
        $this->feed_token = $token;
        $this->feed_token_hash = hash('sha256', $token);
    }

    public static function findByFeedToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()->where('feed_token_hash', hash('sha256', $token))->first();
    }

    /**
     * Op het domein van de site van het assortiment: de URL gaat per mail de
     * deur uit en wordt ook vanuit de console opgebouwd.
     */
    public function feedUrl(string $format): string
    {
        return Sites::url('/reseller-feed/'.$this->feed_token.'/'.self::feedFile($format), $this->assortment?->site_id);
    }

    public static function feedFile(string $format): string
    {
        return self::FEED_FILES[$format] ?? $format.'.csv';
    }

    /** Het formaat achter een bestandsnaam uit de URL, of null als die niet bestaat. */
    public static function feedFormatForFile(string $file): ?string
    {
        return array_search($file, self::FEED_FILES, true) ?: null;
    }

    public function feedPath(string $format): string
    {
        return 'reseller-feeds/'.$this->id.'/'.self::feedFile($format);
    }

    public function feedGeneratedAt(string $format): ?Carbon
    {
        $disk = Storage::disk(config('dashed-ecommerce-reseller.feeds.disk', 'local'));

        return $disk->exists($this->feedPath($format))
            ? Carbon::createFromTimestamp($disk->lastModified($this->feedPath($format)))
            : null;
    }
}
