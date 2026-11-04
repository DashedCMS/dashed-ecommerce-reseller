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

    protected $fillable = ['user_id', 'assortment_id', 'enabled', 'feed_texts', 'feed_images', 'webhook_subscription_id'];

    protected $attributes = ['enabled' => false, 'feed_texts' => true, 'feed_images' => true];

    protected $casts = [
        'enabled' => 'boolean',
        'feed_texts' => 'boolean',
        'feed_images' => 'boolean',
        'feed_token' => 'encrypted',
        'feed_catalog_at' => 'datetime',
        'feed_written_at' => 'datetime',
        'removed_cutoff_at' => 'datetime',
    ];

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

    /**
     * Een product dat uit het assortiment viel staat als verwijderd in de feed
     * (Matrixify: status draft) totdat de afnemer een feed heeft opgehaald
     * waar die regel in stond; daarna laat de feed het product met rust.
     * Matrixify en WP All Import voeren elke import opnieuw uit, en een
     * regel die het product bij elke ophaling weer op concept zet, maakte
     * het de afnemer onmogelijk om het zelf actief te houden (lovora,
     * 4 november 2026: ophaling elk uur, 30 dagen lang).
     *
     * De grens schuift pas op als er sinds het klaarstaan van de vorige
     * bestanden een geslaagde ophaling was, en dan naar het moment waarop
     * de catalogus van die bestanden is opgebouwd: alles wat daarna
     * verwijderd is, zat er nog niet in en gaat nog een keer mee.
     */
    public function advanceRemovedCutoff(): void
    {
        if ($this->feed_catalog_at === null || $this->feed_written_at === null) {
            return;
        }

        $delivered = ApiLog::query()
            ->where('user_id', $this->user_id)
            ->where('path', 'like', '/reseller-feed/%')
            ->where('status', 200)
            ->where('created_at', '>=', $this->feed_written_at)
            ->exists();

        if ($delivered) {
            $this->forceFill(['removed_cutoff_at' => $this->feed_catalog_at])->saveQuietly();
        }
    }

    /** Na het schrijven van alle formaten: de catalogus van dit moment staat nu klaar voor de afnemer. */
    public function recordFeedWritten(Carbon $catalogAt): void
    {
        $this->forceFill(['feed_catalog_at' => $catalogAt, 'feed_written_at' => now()])->saveQuietly();
    }

    public function feedGeneratedAt(string $format): ?Carbon
    {
        $disk = Storage::disk(config('dashed-ecommerce-reseller.feeds.disk', 'local'));

        return $disk->exists($this->feedPath($format))
            ? Carbon::createFromTimestamp($disk->lastModified($this->feedPath($format)))
            : null;
    }
}
