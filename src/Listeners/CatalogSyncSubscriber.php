<?php

namespace Dashed\DashedEcommerceReseller\Listeners;

use Illuminate\Events\Dispatcher;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Jobs\SyncResellerUserJob;
use Dashed\DashedEcommerceReseller\Jobs\SyncResellerProductsJob;
use Dashed\DashedEcommerceReseller\Jobs\SyncResellerPriceGroupJob;
use Dashed\DashedEcommerceCore\Events\Products\ProductInformationUpdatedEvent;
use Dashed\DashedEcommerceCore\Events\PriceGroups\PriceGroupPricesUpdatedEvent;
use Dashed\DashedEcommerceCore\Events\PriceGroups\PriceGroupMembersChangedEvent;

class CatalogSyncSubscriber
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            // Na de voorraad- en prijsherberekening van de hele groep, dus
            // ook bij elke voorraadmutatie.
            ProductInformationUpdatedEvent::class => 'productGroupUpdated',
            PriceGroupPricesUpdatedEvent::class => 'priceGroupPricesUpdated',
            PriceGroupMembersChangedEvent::class => 'priceGroupMembersChanged',
        ];
    }

    public function productGroupUpdated(ProductInformationUpdatedEvent $event): void
    {
        if (! self::hasResellers()) {
            return;
        }

        self::productsChanged(
            Product::withTrashed()->where('product_group_id', $event->productGroup->id)->pluck('id')->all()
        );
    }

    public function priceGroupPricesUpdated(PriceGroupPricesUpdatedEvent $event): void
    {
        if (self::hasResellers()) {
            SyncResellerPriceGroupJob::dispatch($event->priceGroupId);
        }
    }

    public function priceGroupMembersChanged(PriceGroupMembersChangedEvent $event): void
    {
        self::usersChanged($event->userIds);
    }

    public static function productsChanged(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds !== [] && self::hasResellers()) {
            SyncResellerProductsJob::dispatch($productIds);
        }
    }

    public static function usersChanged(array $userIds): void
    {
        if (! self::hasResellers()) {
            return;
        }

        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            SyncResellerUserJob::dispatch($userId);
        }
    }

    public static function hasResellers(): bool
    {
        return ResellerProfile::query()->where('enabled', true)->exists();
    }
}
