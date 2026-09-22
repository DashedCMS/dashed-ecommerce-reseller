<?php

namespace Dashed\DashedEcommerceReseller\Feeds;

/**
 * Een platte CSV voor WP All Import met de WooCommerce-add-on. Variaties
 * hangen aan hun ouder via Parent SKU ("Linking multiple variations
 * together"). Producten die uit het assortiment vallen staan er niet in: de
 * afnemer laat WP All Import die op concept zetten ("records not present in
 * this import file", alleen voor deze import).
 */
final class WooCommerceFeed
{
    private const BASE = [
        'Type', 'SKU', 'Parent SKU', 'Name', 'Description', 'Short description', 'Categories', 'Images',
        'Regular price', 'Cost price', 'Manage stock', 'Stock', 'Stock status',
        'Weight', 'Length', 'Width', 'Height', 'EAN',
    ];

    private ?array $entries = null;

    public function __construct(private FeedCatalog $catalog)
    {
    }

    /**
     * @return list<string>
     */
    public function header(): array
    {
        $header = self::BASE;

        for ($i = 1; $i <= $this->attributeCount(); $i++) {
            $header[] = "Attribute {$i} name";
            $header[] = "Attribute {$i} value(s)";
            $header[] = "Attribute {$i} used for variations";
        }

        return $header;
    }

    /**
     * @return iterable<list<string>>
     */
    public function rows(): iterable
    {
        $header = $this->header();

        foreach ($this->entries() as $entry) {
            if ($entry['group_id'] === null) {
                // WooCommerce gebruikt de SKU als unieke sleutel bij de import
                // ("unique identifier" in WP All Import); zonder SKU vindt
                // een volgende import het product niet terug en zou het
                // dubbel aanmaken. Zo'n product laten we daarom weg.
                if (blank($entry['variants'][0]['product']['sku'] ?? null)) {
                    continue;
                }

                yield $this->row($header, ['Type' => 'simple', ...$this->productFields($entry['variants'][0]['product'], $entry['name']), ...$this->variantFields($entry['variants'][0]['product'])]);

                continue;
            }

            // Zelfde reden per variatie: zonder SKU is er niets om de
            // volgende import op te laten aanhaken, dus die variatie laten we
            // weg. Blijft er geen enkele variatie over, dan is er niets
            // zinnigs te importeren en slaan we de hele groep over.
            $entry['variants'] = array_values(array_filter(
                $entry['variants'],
                fn (array $variant): bool => filled($variant['product']['sku'] ?? null),
            ));

            if ($entry['variants'] === []) {
                continue;
            }

            $parentSku = 'group-'.$entry['group_id'];
            $first = $entry['variants'][0]['product'];
            $parent = ['Type' => 'variable', 'SKU' => $parentSku, ...$this->productFields($first, $entry['name'])];

            // Kolomvolgorde ligt vast per groep (eerste keer dat een naam
            // opduikt over alle varianten), niet per variant. FeedCatalog
            // laat een variant zonder waarde voor een filter dat filter
            // gewoon weg (geen gat), dus de eigen optie-lijst van een
            // variant is al herindexeerd en verschuift zodra een eerdere
            // variant wel alle filters heeft. Op naam opzoeken voorkomt dat
            // Maat in de kolom van Merk terechtkomt.
            $names = $this->attributeNames($entry);
            $valuesByName = $this->attributeValues($entry, $names);

            foreach ($names as $i => $name) {
                $column = $i + 1;
                $parent["Attribute {$column} name"] = $name;
                $parent["Attribute {$column} value(s)"] = implode('|', $valuesByName[$name]);
                $parent["Attribute {$column} used for variations"] = '1';
            }

            yield $this->row($header, $parent);

            foreach ($entry['variants'] as $variant) {
                $values = ['Type' => 'variation', 'Parent SKU' => $parentSku, ...$this->variantFields($variant['product'])];
                $optionByName = collect($variant['options'])->pluck('value', 'name');

                foreach ($names as $i => $name) {
                    $column = $i + 1;
                    $values["Attribute {$column} name"] = $name;
                    $values["Attribute {$column} value(s)"] = (string) $optionByName->get($name, '');
                    $values["Attribute {$column} used for variations"] = '1';
                }

                yield $this->row($header, $values);
            }
        }
    }

    private function entries(): array
    {
        return $this->entries ??= $this->catalog->entries();
    }

    private function attributeCount(): int
    {
        return (int) collect($this->entries())
            ->map(fn (array $entry) => count($this->attributeNames($entry)))
            ->max();
    }

    /**
     * Namen in volgorde van eerste voorkomen over alle varianten van de
     * groep heen. Elke variant levert zijn eigen subset van de
     * groepsfilters op (FeedCatalog::variantOptions() laat een ontbrekend
     * filter weg in plaats van er een gat voor te laten), maar die subset
     * behoudt de onderlinge volgorde van de filters. Zodra alle varianten
     * langsgekomen zijn ligt daarom de volledige, groep-brede volgorde vast.
     *
     * @return list<string>
     */
    private function attributeNames(array $entry): array
    {
        $names = [];

        foreach ($entry['variants'] as $variant) {
            foreach ($variant['options'] as $option) {
                if (! in_array($option['name'], $names, true)) {
                    $names[] = $option['name'];
                }
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>> naam => gededupliceerde waarden, in volgorde van voorkomen
     */
    private function attributeValues(array $entry, array $names): array
    {
        $values = array_fill_keys($names, []);

        foreach ($entry['variants'] as $variant) {
            foreach ($variant['options'] as $option) {
                if (! in_array($option['value'], $values[$option['name']], true)) {
                    $values[$option['name']][] = $option['value'];
                }
            }
        }

        return $values;
    }

    private function productFields(array $p, string $name): array
    {
        return [
            'Name' => $name,
            'Description' => (string) ($p['description'] ?? ''),
            'Short description' => (string) ($p['short_description'] ?? ''),
            // Platte namen, geen "Ouder > kind"-boom: ProductPresenter geeft
            // van een categorie alleen {id, name}, zonder ouderketen. Een
            // bewuste vereenvoudiging ten opzichte van de WP All
            // Import-spec.
            'Categories' => implode('|', array_column($p['categories'] ?? [], 'name')),
            'Images' => implode('|', $p['images'] ?? []),
        ];
    }

    private function variantFields(array $p): array
    {
        $stock = $p['stock'];
        $managed = $stock['mode'] !== 'status' && ! $stock['unlimited'];

        return [
            'SKU' => (string) ($p['sku'] ?? ''),
            'EAN' => (string) ($p['ean'] ?? ''),
            'Regular price' => self::money($p['advice_price'] ?? null),
            'Cost price' => self::money($p['purchase_price'] ?? null),
            'Manage stock' => $managed ? 'yes' : 'no',
            'Stock' => $managed ? (string) (int) $stock['quantity'] : '',
            'Stock status' => $stock['in_stock'] ? 'instock' : 'outofstock',
            'Weight' => self::decimal($p['weight'] ?? null),
            'Length' => self::decimal($p['length'] ?? null),
            'Width' => self::decimal($p['width'] ?? null),
            'Height' => self::decimal($p['height'] ?? null),
        ];
    }

    /**
     * @param  list<string>  $header
     * @return list<string>
     */
    private function row(array $header, array $values): array
    {
        return array_map(fn (string $column) => (string) ($values[$column] ?? ''), $header);
    }

    private static function money(mixed $value): string
    {
        return $value === null ? '' : number_format((float) $value, 2, '.', '');
    }

    private static function decimal(mixed $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }
}
