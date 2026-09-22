<?php

namespace Dashed\DashedEcommerceReseller\Feeds;

/**
 * Het productformaat van Matrixify (Shopify). Eén rij per variant, alle
 * rijen van een product met dezelfde Handle; productvelden alleen op de
 * eerste rij. Kolomnamen zijn exact die van Matrixify:
 * https://matrixify.app/documentation/products/
 */
final class ShopifyFeed
{
    public const HEADER = [
        'Handle', 'Command', 'Title', 'Body HTML', 'Vendor', 'Type', 'Tags', 'Status',
        'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value', 'Option3 Name', 'Option3 Value',
        'Variant Command', 'Variant SKU', 'Variant Barcode', 'Variant Price', 'Variant Cost',
        'Variant Inventory Tracker', 'Variant Inventory Policy', 'Variant Inventory Qty',
        'Variant Weight', 'Variant Weight Unit', 'Image Src',
    ];

    /** Shopify kent maximaal drie opties per product. */
    private const MAX_OPTIONS = 3;

    /**
     * @return iterable<list<string>>
     */
    public static function rows(FeedCatalog $catalog): iterable
    {
        $entries = $catalog->entries();
        $vendor = $catalog->siteName();

        foreach ($entries as $entry) {
            // Optienamen liggen per entry vast (eerste keer dat een naam
            // opduikt over alle varianten heen), niet per variant. Een
            // variant zonder waarde voor een van de groepsfilters mist dat
            // filter in zijn eigen, al herindexeerde optie-lijst
            // (FeedCatalog::variantOptions() laat het gewoon weg), dus op
            // positie kopiëren zou die variant een verkeerd optienaam geven.
            $names = self::optionNames($entry);

            foreach ($entry['variants'] as $index => $variant) {
                yield self::variantRow($entry, $variant, $index === 0, $vendor, $names);
            }
        }

        // UPDATE en nooit MERGE: MERGE maakt een product dat de afnemer zelf
        // al verwijderde opnieuw aan. En nooit DELETE: dan is zijn eigen werk
        // aan het product weg.
        $draftHandles = [];

        foreach ($catalog->removed(array_column($entries, 'handle')) as $removed) {
            if ($removed['whole_product']) {
                // Een gegroepeerd product dat al zijn varianten kwijtraakte
                // levert per variant een eigen removed()-regel met dezelfde
                // handle op; zonder dedupe komt dezelfde conceptrij er
                // meerdere keren in, en Matrixify kent geen kolom die dat
                // als hetzelfde product herkent.
                if (isset($draftHandles[$removed['handle']])) {
                    continue;
                }

                $draftHandles[$removed['handle']] = true;

                yield self::row(['Handle' => $removed['handle'], 'Command' => 'UPDATE', 'Status' => 'draft']);

                continue;
            }

            if ($removed['sku'] === null || $removed['sku'] === '') {
                // Geen sku om op te richten: zonder Variant SKU valt Variant
                // Command terug op MERGE en zou de rij juist een variant
                // kunnen aanmaken in plaats van hem weg te halen. Zo'n rij
                // is voor Matrixify dubbelzinnig, dus laten we hem weg.
                continue;
            }

            yield self::row(['Handle' => $removed['handle'], 'Command' => 'UPDATE', 'Variant Command' => 'DELETE', 'Variant SKU' => (string) $removed['sku']]);
        }
    }

    /**
     * @return list<string> hooguit MAX_OPTIONS namen, in volgorde van eerste voorkomen
     */
    private static function optionNames(array $entry): array
    {
        $names = [];

        foreach ($entry['variants'] as $variant) {
            foreach ($variant['options'] as $option) {
                if (! in_array($option['name'], $names, true)) {
                    $names[] = $option['name'];
                }

                if (count($names) >= self::MAX_OPTIONS) {
                    return $names;
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private static function variantRow(array $entry, array $variant, bool $first, string $vendor, array $names): array
    {
        $p = $variant['product'];

        if ($names === []) {
            $names = ['Title'];
            $optionByName = collect(['Title' => 'Default Title']);
        } else {
            $optionByName = collect($variant['options'])->pluck('value', 'name');
        }

        $categories = array_column($p['categories'] ?? [], 'name');

        $values = [
            'Handle' => $entry['handle'],
            'Command' => 'MERGE',
            'Status' => 'active',
            'Variant Command' => 'MERGE',
            'Variant SKU' => (string) ($p['sku'] ?? ''),
            'Variant Barcode' => (string) ($p['ean'] ?? ''),
            'Variant Price' => self::money($p['advice_price'] ?? null),
            'Variant Cost' => self::money($p['purchase_price'] ?? null),
            'Variant Weight' => $p['weight'] === null ? '' : self::decimal($p['weight']),
            'Variant Weight Unit' => 'kg',
            ...self::stock($p['stock']),
        ];

        foreach ($names as $i => $name) {
            $values['Option'.($i + 1).' Name'] = $name;
            $values['Option'.($i + 1).' Value'] = (string) $optionByName->get($name, '');
        }

        if ($first) {
            $values += [
                'Title' => $entry['name'],
                'Body HTML' => (string) ($p['description'] ?? ''),
                'Vendor' => $vendor,
                'Type' => (string) ($categories[0] ?? ''),
                'Tags' => implode(', ', $categories),
                'Image Src' => implode(';', $p['images'] ?? []),
            ];
        }

        return self::row($values);
    }

    /**
     * @return array<string, string>
     */
    private static function stock(array $stock): array
    {
        $tracked = fn (int $qty) => ['Variant Inventory Tracker' => 'shopify', 'Variant Inventory Policy' => 'deny', 'Variant Inventory Qty' => (string) $qty];
        $untracked = ['Variant Inventory Tracker' => '', 'Variant Inventory Policy' => 'continue', 'Variant Inventory Qty' => ''];

        if ($stock['mode'] === 'status') {
            return $stock['in_stock'] ? $untracked : $tracked(0);
        }

        if ($stock['unlimited']) {
            return $untracked;
        }

        return $tracked((int) $stock['quantity']);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private static function row(array $values): array
    {
        return array_map(fn (string $column) => (string) ($values[$column] ?? ''), self::HEADER);
    }

    private static function money(mixed $value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    private static function decimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }
}
