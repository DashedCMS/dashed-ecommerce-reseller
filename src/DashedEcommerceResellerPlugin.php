<?php

namespace Dashed\DashedEcommerceReseller;

use Filament\Panel;
use Filament\Contracts\Plugin;

class DashedEcommerceResellerPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-reseller';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            \Dashed\DashedEcommerceReseller\Filament\Resources\AssortmentResource::class,
            \Dashed\DashedEcommerceReseller\Filament\Resources\ResellerResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
    }
}
