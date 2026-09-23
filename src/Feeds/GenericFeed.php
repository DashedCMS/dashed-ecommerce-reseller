<?php

namespace Dashed\DashedEcommerceReseller\Feeds;

use XMLWriter;

/**
 * De open feed: dezelfde inhoud als de twee importfeeds, maar zonder de vorm
 * van een platform. Voor een afnemer die niet op Shopify of WooCommerce zit
 * en zijn koppeling zelf bouwt, en die geen API-sleutel wil beheren.
 *
 * Dezelfde velden als de CSV's, niet alles wat de API geeft. De keuze is
 * bewust: wat hier staat is wat een importfeed ook zegt, dus een afnemer die
 * later overstapt van JSON naar Matrixify krijgt geen andere gegevens.
 * Wie meer wil (vertalingen, kenmerken, url's) gebruikt de API.
 *
 * JSON en XML zijn dezelfde boom. De XML is bewust plat en zonder namespace:
 * elk importtool leest dit, en er gaat geen veld verloren in een vorm die
 * voor iets anders bedoeld is (Google Shopping bijvoorbeeld kent geen
 * inkoopprijs).
 */
final class GenericFeed
{
    public function __construct(private FeedCatalog $catalog)
    {
    }

    public function json(): string
    {
        return (string) json_encode($this->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Met XMLWriter en niet met een string of SimpleXML: de writer ontsnapt
     * elke waarde zelf, en een productomschrijving bevat HTML.
     */
    public function xml(): string
    {
        $data = $this->data();

        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('feed');

        foreach (['generated_at', 'locale', 'vendor', 'currency'] as $key) {
            $writer->writeElement($key, (string) $data[$key]);
        }

        $writer->startElement('products');

        foreach ($data['products'] as $product) {
            $this->writeProduct($writer, $product);
        }

        $writer->endElement();

        $writer->startElement('removed');

        foreach ($data['removed'] as $removed) {
            $writer->startElement('product');
            $writer->writeElement('handle', (string) $removed['handle']);
            $writer->writeElement('sku', (string) $removed['sku']);
            $writer->writeElement('whole_product', $removed['whole_product'] ? 'true' : 'false');
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * @return array{generated_at: string, locale: string, vendor: string, currency: string, products: list<array<string, mixed>>, removed: list<array<string, mixed>>}
     */
    public function data(): array
    {
        $entries = $this->catalog->entries();

        return [
            'generated_at' => now()->toIso8601String(),
            'locale' => $this->catalog->locale(),
            'vendor' => $this->catalog->siteName(),
            'currency' => (string) config('dashed-ecommerce-reseller.currency', 'EUR'),
            'products' => array_map(fn (array $entry): array => $this->product($entry), $entries),
            // Producten die uit het assortiment vielen. De importfeeds zetten
            // ze op concept; wat een eigen koppeling ermee doet, bepaalt de
            // afnemer, dus ze staan hier apart in plaats van als rij met een
            // statusvlag ertussen.
            'removed' => $this->catalog->removed(array_column($entries, 'handle')),
        ];
    }

    /**
     * @param  array{handle: string, group_id: ?int, name: string, variants: list<array{product: array, options: list<array{name: string, value: string}>}>}  $entry
     * @return array<string, mixed>
     */
    private function product(array $entry): array
    {
        $first = $entry['variants'][0]['product'] ?? [];

        return [
            'handle' => $entry['handle'],
            'name' => $entry['name'],
            // Waar of niet: of dit een groep met varianten is. Een afnemer
            // moet dat kunnen zien zonder het aantal varianten te tellen, want
            // een groep met één variant blijft een groep (zie FeedCatalog).
            'grouped' => $entry['group_id'] !== null,
            'description' => (string) ($first['description'] ?? ''),
            'short_description' => (string) ($first['short_description'] ?? ''),
            'categories' => array_values(array_map(
                fn (array $category): string => (string) $category['name'],
                (array) ($first['categories'] ?? []),
            )),
            'images' => array_values((array) ($first['images'] ?? [])),
            'variants' => array_map(fn (array $variant): array => $this->variant($variant), $entry['variants']),
        ];
    }

    /**
     * @param  array{product: array, options: list<array{name: string, value: string}>}  $variant
     * @return array<string, mixed>
     */
    private function variant(array $variant): array
    {
        $product = $variant['product'];
        $stock = (array) ($product['stock'] ?? []);

        return [
            'sku' => $product['sku'] ?? null,
            'ean' => $product['ean'] ?? null,
            // Dezelfde twee bedragen als in de CSV's: wat de afnemer betaalt
            // (purchase_price, exclusief btw) en onze adviesprijs.
            'purchase_price' => $product['purchase_price'] ?? null,
            'advice_price' => $product['advice_price'] ?? null,
            'vat_rate' => $product['vat_rate'] ?? null,
            'stock' => [
                'quantity' => $stock['quantity'] ?? null,
                'in_stock' => (bool) ($stock['in_stock'] ?? false),
                'unlimited' => (bool) ($stock['unlimited'] ?? false),
            ],
            'weight' => $product['weight'] ?? null,
            'length' => $product['length'] ?? null,
            'width' => $product['width'] ?? null,
            'height' => $product['height'] ?? null,
            'options' => $variant['options'],
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function writeProduct(XMLWriter $writer, array $product): void
    {
        $writer->startElement('product');

        $writer->writeElement('handle', (string) $product['handle']);
        $writer->writeElement('name', (string) $product['name']);
        $writer->writeElement('grouped', $product['grouped'] ? 'true' : 'false');
        $writer->writeElement('description', (string) $product['description']);
        $writer->writeElement('short_description', (string) $product['short_description']);

        $this->writeList($writer, 'categories', 'category', $product['categories']);
        $this->writeList($writer, 'images', 'image', $product['images']);

        $writer->startElement('variants');

        foreach ($product['variants'] as $variant) {
            $writer->startElement('variant');

            foreach (['sku', 'ean', 'purchase_price', 'advice_price', 'vat_rate', 'weight', 'length', 'width', 'height'] as $key) {
                $writer->writeElement($key, self::scalar($variant[$key]));
            }

            $writer->startElement('stock');
            $writer->writeElement('quantity', self::scalar($variant['stock']['quantity']));
            $writer->writeElement('in_stock', $variant['stock']['in_stock'] ? 'true' : 'false');
            $writer->writeElement('unlimited', $variant['stock']['unlimited'] ? 'true' : 'false');
            $writer->endElement();

            $writer->startElement('options');

            foreach ($variant['options'] as $option) {
                $writer->startElement('option');
                $writer->writeAttribute('name', (string) $option['name']);
                $writer->text((string) $option['value']);
                $writer->endElement();
            }

            $writer->endElement();
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
    }

    /**
     * @param  list<string>  $values
     */
    private function writeList(XMLWriter $writer, string $wrapper, string $item, array $values): void
    {
        $writer->startElement($wrapper);

        foreach ($values as $value) {
            $writer->writeElement($item, (string) $value);
        }

        $writer->endElement();
    }

    private static function scalar(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
