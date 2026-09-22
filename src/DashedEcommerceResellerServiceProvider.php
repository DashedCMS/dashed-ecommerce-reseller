<?php

namespace Dashed\DashedEcommerceReseller;

use Dashed\DashedCore\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Dashed\DashedCore\Retention\Termijn;
use Dashed\DashedCore\Retention\Retention;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Models\Assortment;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Jobs\SyncResellerUserJob;
use Dashed\DashedEcommerceReseller\Jobs\SyncResellerAssortmentJob;
use Dashed\DashedEcommerceReseller\Listeners\CatalogSyncSubscriber;

class DashedEcommerceResellerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('dashed-ecommerce-reseller')
            // Volgorde telt niet, de bestandsnamen wel: de migrator sorteert
            // op naam, en profielen verwijzen naar assortimenten en naar de
            // webhooktabel van dashed-core (2026_09_17_11xxxx).
            ->hasMigrations([
                '2026_09_17_120000_create_reseller_assortments_table',
                '2026_09_17_120100_create_reseller_profiles_table',
                '2026_09_17_120200_create_reseller_catalog_items_table',
                '2026_09_17_120300_create_reseller_api_logs_table',
                '2026_09_22_120000_add_feed_token_to_reseller_profiles_table',
            ])
            ->runsMigrations()
            ->hasConfigFile()
            ->hasViews('dashed-ecommerce-reseller')
            ->hasCommand(\Dashed\DashedEcommerceReseller\Commands\ReconcileResellerCatalogCommand::class)
            ->hasRoutes(['reseller-api', 'reseller-feed']);
    }

    public function packageBooted(): void
    {
        cms()->builder('plugins', [
            new DashedEcommerceResellerPlugin(),
        ]);

        \Illuminate\Support\Facades\Gate::policy(Assortment::class, \Dashed\DashedEcommerceReseller\Policies\AssortmentPolicy::class);
        \Illuminate\Support\Facades\Gate::policy(ResellerProfile::class, \Dashed\DashedEcommerceReseller\Policies\ResellerProfilePolicy::class);

        cms()->registerRolePermissions('Afnemers', [
            'view_assortment' => __('Assortimenten bekijken'),
            'edit_assortment' => __('Assortimenten bewerken'),
            'delete_assortment' => __('Assortimenten verwijderen'),
            'view_reseller_profile' => __('Afnemers bekijken'),
            'edit_reseller_profile' => __('Afnemers en hun sleutels beheren'),
            'delete_reseller_profile' => __('Afnemers verwijderen'),
        ]);

        if (method_exists(cms(), 'registerWebhookEvents')) {
            \Dashed\DashedEcommerceReseller\Webhooks\ResellerWebhooks::register();
        }

        Event::subscribe(CatalogSyncSubscriber::class);

        Product::deleted(fn (Product $product) => CatalogSyncSubscriber::productsChanged([$product->id]));
        Product::restored(fn (Product $product) => CatalogSyncSubscriber::productsChanged([$product->id]));

        // Modelevents vuren op de eigen klasse. Een project gebruikt
        // App\Models\User, het CMS soms de basisklasse; allebei aanmelden.
        foreach ($this->userModelClasses() as $class) {
            $class::updated(function ($user): void {
                if ($user->wasChanged(['price_group_id', 'has_custom_pricing', 'role'])) {
                    CatalogSyncSubscriber::usersChanged([$user->getKey()]);
                }
            });
        }

        // Los van elkaar in plaats van op saved()+wasRecentlyCreated: die vlag
        // blijft op hetzelfde PHP-object true staan na de eerste insert, ook
        // bij een latere update() die niets met afnemers te maken heeft.
        ResellerProfile::created(function (ResellerProfile $profile): void {
            SyncResellerUserJob::dispatch((int) $profile->user_id);
        });

        ResellerProfile::updated(function (ResellerProfile $profile): void {
            if ($profile->wasChanged(['enabled', 'assortment_id'])) {
                SyncResellerUserJob::dispatch((int) $profile->user_id);
            }
        });

        Assortment::saved(function (Assortment $assortment): void {
            if ($assortment->profiles()->exists()) {
                SyncResellerAssortmentJob::dispatch($assortment->id);
            }
        });

        $this->app->booted(function (): void {
            app(Schedule::class)
                ->command('reseller:reconcile')
                ->dailyAt('03:00')
                ->withoutOverlapping();
        });

        if (method_exists(\Dashed\DashedCore\Classes\RateLimits::class, 'extend')) {
            \Dashed\DashedCore\Classes\RateLimits::extend(
                'dashed-reseller-api',
                'rate_limit_reseller_api',
                (int) config('dashed-ecommerce-reseller.api.rate_limit_default', 300),
                __('Afnemers-API'),
                __('Per API-sleutel per minuut. Standaard 300.'),
                by: 'token',
            );

            \Dashed\DashedCore\Classes\RateLimits::extend(
                'dashed-reseller-feed',
                'rate_limit_reseller_feed',
                (int) config('dashed-ecommerce-reseller.feeds.rate_limit_default', 60),
                __('Afnemersfeeds'),
                __('Ophalingen van een Shopify- of WooCommerce-feed per IP-adres per minuut. Standaard 60.'),
            );
        }

        // De sleutel zit in het pad, niet achter een querystring of header;
        // zoekmachines mogen deze feeds dus nooit indexeren of volgen.
        cms()->builder(\Dashed\DashedCore\Classes\RobotsTxtBuilder::BUILDER, [
            '/reseller-feed/',
        ]);

        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (\Throwable $e, \Illuminate\Http\Request $request) {
                if (\Dashed\DashedEcommerceReseller\Http\ApiError::matches($request)) {
                    return \Dashed\DashedEcommerceReseller\Http\ApiError::render($e);
                }

                return null;
            });
        }

        self::registreerBewaartermijnen();

        // De mailable meldt zich aan bij het sjablonenregister, zodat de
        // beheerder onderwerp en blokken kan aanpassen zoals bij elke
        // andere systeemmail.
        if (method_exists(cms(), 'registerMailable')) {
            cms()->registerMailable(\Dashed\DashedEcommerceReseller\Mail\ResellerSetupMail::class);
        }

        if (method_exists(cms(), 'registerIntegration')) {
            cms()->registerIntegration([
                'slug' => 'reseller-api',
                'label' => __('Afnemers-API'),
                'icon' => 'heroicon-o-building-storefront',
                'category' => 'other',
                'permission' => 'view_reseller_profile',
                'health_check' => fn (?string $siteId = null) => \Dashed\DashedEcommerceReseller\Support\ResellerHealth::check($siteId),
                'package' => 'dashed-ecommerce-reseller',
            ]);
        }
    }

    /**
     * @return list<class-string>
     */
    private function userModelClasses(): array
    {
        $configured = (string) config('auth.providers.users.model', User::class);

        return array_values(array_unique(array_filter(
            [User::class, $configured],
            fn (string $class) => class_exists($class) && is_a($class, User::class, true),
        )));
    }

    /**
     * Statisch, zodat een test het register kan legen en opnieuw vullen.
     * Nooit vanuit register(): de labels hebben de vertaalservice nodig.
     */
    public static function registreerBewaartermijnen(): void
    {
        cms()->registerRetention(
            Retention::make('reseller_api_logs')
                ->label(__('API-aanroepen van afnemers'))
                ->pakket('dashed-ecommerce-reseller', __('Afnemers'))
                ->tabel('dashed__reseller_api_logs')
                ->termijn(
                    Termijn::make('reseller_api_logs', fn () => (int) config('dashed-ecommerce-reseller.retention.api_logs_days', 30), 'created_at')
                        ->label(__('API-aanroepen bewaren (dagen)'))
                        ->uitleg(__('Elke aanroep van een afnemerssleutel, met pad, status en duur. Standaard: 30 dagen.'))
                )
        );

        cms()->registerRetention(
            Retention::make('reseller_catalog_removed')
                ->label(__('Verwijderde producten in afnemerscatalogi'))
                ->pakket('dashed-ecommerce-reseller', __('Afnemers'))
                ->tabel('dashed__reseller_catalog_items')
                ->termijn(
                    // De opruimer vergelijkt removed_at met de grens, dus een
                    // regel zonder removed_at (een actief product) valt er
                    // nooit onder.
                    Termijn::make('reseller_catalog_removed', fn () => (int) config('dashed-ecommerce-reseller.retention.removed_items_days', 30), 'removed_at')
                        ->label(__('Verwijderde producten bewaren (dagen)'))
                        ->uitleg(__('Zo lang ziet een afnemer via "gewijzigd sinds" nog dat een product weg is. Een koppeling die minder vaak ophaalt mist die melding. Standaard: 30 dagen.'))
                )
        );
    }
}
