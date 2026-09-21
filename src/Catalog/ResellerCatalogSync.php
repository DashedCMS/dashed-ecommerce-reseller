<?php

namespace Dashed\DashedEcommerceReseller\Catalog;

use Throwable;
use Dashed\DashedCore\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceReseller\Models\CatalogItem;
use Dashed\DashedEcommerceReseller\Models\ResellerProfile;
use Dashed\DashedEcommerceReseller\Webhooks\ResellerWebhooks;

/**
 * Houdt per afnemer bij wat hij van elk product gezien heeft (als
 * vingerafdruk), zodat "gewijzigd sinds" en de webhooks precies zeggen wat
 * er voor hem veranderde. Een prijsgroepwijziging raakt de updated_at van
 * een product niet, en een product dat uit een assortiment valt heeft geen
 * rij meer om te melden; daarom deze tabel.
 */
class ResellerCatalogSync
{
    public const LAST_RECONCILE_KEY = 'dashed-reseller:last-reconcile';

    private const RELATIONS = ['user', 'assortment.rules', 'webhookSubscription'];

    public function __construct(private ProductPresenter $presenter)
    {
    }

    public function syncProducts(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return;
        }

        foreach ($this->activeProfiles() as $profile) {
            foreach (array_chunk($productIds, $this->chunkSize()) as $chunk) {
                $this->syncChunk($profile, $chunk);
            }
        }
    }

    public function syncProfile(ResellerProfile $profile): void
    {
        $profile->loadMissing(self::RELATIONS);

        if (! $profile->isActive()) {
            return;
        }

        $seen = [];

        $profile->assortment->productQuery()
            ->select('dashed__products.id')
            ->chunkById($this->chunkSize(), function (Collection $rows) use ($profile, &$seen) {
                $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
                $this->syncChunk($profile, $ids);

                foreach ($ids as $id) {
                    $seen[$id] = true;
                }
            }, 'dashed__products.id', 'id');

        $gone = CatalogItem::query()
            ->where('user_id', $profile->user_id)
            ->active()
            ->pluck('product_id')
            ->reject(fn ($id) => isset($seen[(int) $id]))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        foreach (array_chunk($gone, $this->chunkSize()) as $chunk) {
            $this->syncChunk($profile, $chunk);
        }
    }

    public function syncUsers(array $userIds): void
    {
        ResellerProfile::query()
            ->with(self::RELATIONS)
            ->whereIn('user_id', $userIds)
            ->get()
            ->each(fn (ResellerProfile $profile) => $this->syncProfile($profile));
    }

    public function syncPriceGroup(int $priceGroupId): void
    {
        $this->syncUsers(User::query()->where('price_group_id', $priceGroupId)->pluck('id')->all());
    }

    public function syncAssortment(int $assortmentId): void
    {
        ResellerProfile::query()
            ->with(self::RELATIONS)
            ->where('assortment_id', $assortmentId)
            ->get()
            ->each(fn (ResellerProfile $profile) => $this->syncProfile($profile));
    }

    public function reconcile(): int
    {
        $count = 0;

        ResellerProfile::query()->active()->with(self::RELATIONS)->each(function (ResellerProfile $profile) use (&$count) {
            $this->syncProfile($profile);
            $count++;
        });

        Cache::forever(self::LAST_RECONCILE_KEY, now()->toIso8601String());

        return $count;
    }

    /**
     * @param  list<int>  $productIds
     */
    private function syncChunk(ResellerProfile $profile, array $productIds): void
    {
        $locales = $profile->locales();

        $products = $profile->assortment->productQuery()
            ->whereIn('dashed__products.id', $productIds)
            ->with(ProductPresenter::EAGER)
            ->get()
            ->keyBy('id');

        $items = CatalogItem::query()
            ->where('user_id', $profile->user_id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        foreach ($productIds as $productId) {
            try {
                $product = $products->get($productId);
                $item = $items->get($productId);

                if ($product !== null) {
                    $this->upsert($profile, $product, $item, $locales);
                } elseif ($item !== null && $item->removed_at === null) {
                    $this->markRemoved($profile, $item);
                }
            } catch (Throwable $e) {
                // Eén kapot product mag de rest van de portie niet
                // tegenhouden. De nachtelijke afstemming probeert het opnieuw.
                report($e);
            }
        }
    }

    private function upsert(ResellerProfile $profile, Product $product, ?CatalogItem $item, array $locales): void
    {
        $payload = $this->presenter->present($product, $profile, $locales);
        $fingerprint = Fingerprint::of($payload);

        if ($item !== null && $item->removed_at === null && $item->fingerprint === $fingerprint) {
            return;
        }

        $item ??= new CatalogItem(['user_id' => $profile->user_id, 'product_id' => $product->id]);
        $item->fill(['fingerprint' => $fingerprint, 'changed_at' => now(), 'removed_at' => null])->save();

        $payload['removed'] = false;
        $payload['changed_at'] = $item->changed_at->toIso8601String();

        ResellerWebhooks::notify($profile, ResellerWebhooks::EVENT_UPDATED, $payload);
    }

    private function markRemoved(ResellerProfile $profile, CatalogItem $item): void
    {
        $item->fill(['removed_at' => now(), 'changed_at' => now()])->save();

        ResellerWebhooks::notify($profile, ResellerWebhooks::EVENT_REMOVED, [
            'id' => (int) $item->product_id,
            'removed' => true,
            'changed_at' => $item->changed_at->toIso8601String(),
        ]);
    }

    /**
     * @return Collection<int, ResellerProfile>
     */
    private function activeProfiles(): Collection
    {
        return ResellerProfile::query()
            ->active()
            ->with(self::RELATIONS)
            ->get()
            ->filter(fn (ResellerProfile $profile) => $profile->isActive())
            ->values();
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('dashed-ecommerce-reseller.sync.chunk', 500));
    }
}
