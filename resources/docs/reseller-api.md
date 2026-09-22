# Afnemers-API

Met deze API haal je ons assortiment op zoals het voor jouw account is ingesteld: producten, jouw inkoopprijs, onze adviesprijs en de voorraad. Wijzigingen krijg je door via `updated_since` of via een webhook.

## Toegang

Je krijgt een sleutel van ons. Stuur hem mee als bearer-token, altijd via https:

```
GET https://<domein>/api/reseller/v1/me
Authorization: Bearer 12|abcdef...
Accept: application/json
```

Het verzoek moet bij onze server als https aankomen. Staat er een proxy of load balancer voor die intern over http doorstuurt en is dat niet ingesteld, dan krijg je een 403 `https_required`; neem dan contact met ons op.

Er geldt een limiet per sleutel (standaard 300 verzoeken per minuut). Daarboven krijg je een 429 met een `Retry-After`-header.

## Feeds voor Shopify en WooCommerce

Wil je geen eigen koppeling bouwen, dan kun je ons assortiment als bestand
inlezen. Je krijgt van ons twee links, met een eigen sleutel erin:

- `https://<domein>/reseller-feed/<sleutel>/shopify.csv`, in het formaat van
  Matrixify (Shopify). Varianten staan onder één Handle. Een product dat uit
  je assortiment gaat komt 30 dagen mee als concept (`Status = draft`,
  `Command = UPDATE`), een weggevallen variant als `Variant Command = DELETE`.
- `https://<domein>/reseller-feed/<sleutel>/woocommerce.csv`, voor WP All
  Import. Variaties hangen aan hun ouder via `Parent SKU`; de ouder heeft SKU
  `group-<id>`. Producten die weggaan staan er niet meer in: laat WP All Import
  "records not present in this import file" op concept zetten.

Verkoopprijs is onze adviesprijs inclusief btw, kostprijs is jouw inkoopprijs
exclusief btw.
Voorraad volgt de weergave van je assortiment. Categorieen staan als platte
namen, zonder boom. Het bestand wordt bijgewerkt kort nadat er iets verandert
en elke nacht; vaker ophalen dan elk uur heeft weinig zin. Een verkeerde of
ingetrokken sleutel geeft een 404. Krijg je een 503 met een `Retry-After`-
header, dan bestond het bestand nog niet en wordt het op dit moment
klaargezet; probeer het na die tijd opnieuw.

Een product zonder eigen artikelnummer (SKU) staat niet in de
WooCommerce-feed: WP All Import gebruikt de SKU als unieke sleutel om een
product bij een volgende import terug te vinden, en zonder SKU zou hij het
telkens opnieuw aanmaken. Een variatie zonder SKU ontbreekt op dezelfde
manier; blijft er van een gegroepeerd product geen enkele variatie over, dan
ontbreekt de hele groep. In de Shopify-feed blijven zulke producten gewoon
staan, want Matrixify herkent een product op Handle en optiewaarden, niet op
SKU.

Hernoem je een product bij ons, dan verandert de URL-slug en daarmee de
Handle in de Shopify-feed. Matrixify herkent de nieuwe Handle niet als
hetzelfde product en maakt in je winkel een nieuw product aan; het oude
product met de oude naam blijft gewoon staan. Zet daarom voor een product dat
je via afnemers verkoopt een vaste slug, of wees je bewust van dit gevolg
voordat je een productnaam wijzigt.

## Fouten

Elke fout heeft dezelfde vorm:

```json
{ "error": { "code": "invalid_parameters", "message": "..." } }
```

| Status | Code | Betekenis |
|---|---|---|
| 401 | `unauthenticated` | Geen of een ongeldige sleutel |
| 403 | `forbidden` | Dit is geen afnemerssleutel |
| 403 | `access_disabled` | Toegang staat uit voor dit account |
| 403 | `https_required` | Verzoek niet via https |
| 404 | `not_found` | Bestaat niet of valt buiten je assortiment |
| 422 | `invalid_parameters` | Een parameter klopt niet |
| 429 | `rate_limited` | Te veel verzoeken |
| 500 | `server_error` | Fout aan onze kant |

## Endpoints

### `GET /me`

Je account, je assortiment, de voorraadweergave, de beschikbare talen (`locales`, de eerste is de standaard), de valuta en `server_time`. Handig om een nieuwe sleutel te testen.

### `GET /products`

| Parameter | Uitleg |
|---|---|
| `per_page` | 1 tot 250, standaard 100 |
| `cursor` | De `meta.next_cursor` van de vorige pagina |
| `locale` | Een taalcode uit `/me`, of `all` voor alle talen onder `translations` |
| `updated_since` | ISO 8601. Alleen wat daarna veranderde, inclusief producten die uit je assortiment gingen |

Antwoord:

```json
{
  "data": [
    {
      "id": 123,
      "product_group_id": 45,
      "sku": "KOP-25-ZW",
      "ean": "8712345678901",
      "purchase_price": 50.0,
      "purchase_price_incl": 60.5,
      "advice_price": 121.0,
      "vat_rate": 21.0,
      "currency": "EUR",
      "stock": { "mode": "exact", "quantity": 5, "capped": false, "unlimited": false, "in_stock": true, "expected_in_stock_date": null },
      "width": 10.0, "height": 20.0, "length": 30.0, "weight": 1.5,
      "name": "Koppel zwart 25cm",
      "slug": "koppel-zwart-25cm",
      "url": "https://<domein>/producten/koppel-zwart-25cm",
      "short_description": "...",
      "description": "<p>...</p>",
      "images": ["https://..."],
      "categories": [{ "id": 7, "name": "Koppels" }],
      "attributes": { "Kleur": "Zwart", "Lengte": "25cm" },
      "removed": false,
      "changed_at": "2026-09-17T10:15:00+02:00"
    },
    { "id": 124, "removed": true, "changed_at": "2026-09-17T10:16:00+02:00" }
  ],
  "meta": { "next_cursor": 124, "per_page": 100, "server_time": "2026-09-17T10:20:00+02:00" }
}
```

Een verwijderd product staat er alleen in met `updated_since`, en ongeveer dertig dagen lang. Haal vaker op dan dat.

**Bijhouden van wijzigingen.** Bewaar de `server_time` van de eerste pagina en gebruik die de volgende keer als `updated_since`. Blader door tot `next_cursor` `null` is. Stuur `server_time` letterlijk terug, met de offset erin; dat is de veilige weg. Stuur je zelf een tijd, dan houden we rekening met de tijdzone die je meegeeft. Een product dat precies op het moment van `updated_since` veranderde komt opnieuw mee, dus je kunt een product twee keer ontvangen. Verwerk het dan gewoon nog een keer.

**Voorraad.** `stock.mode` zegt hoe we voorraad tonen: `exact` (het aantal), `capped` (`quantity` is nooit hoger dan het plafond, `capped` is `true` als er meer is) of `status` (geen aantal, alleen `in_stock`). `unlimited: true` betekent dat het product altijd leverbaar is.

**Prijzen.** `purchase_price` is wat je ons betaalt, exclusief btw. `advice_price` is onze consumentenprijs inclusief btw.

### `GET /products/{id}`

Eén product, met `locale`. Buiten je assortiment: 404.

### `GET /stock?ids=1,2,3`

Alleen voorraad, voor tot 250 ids tegelijk. Ids buiten je assortiment worden weggelaten.

### `GET /product-groups`

Producten met varianten, voor platforms die daarmee werken. Parameters als bij `/products`. Een variant heeft `options` met filter en waarde (bijvoorbeeld Maat: Klein). Een verwijderde variant zie je alleen bij `/products`.

### `GET /categories`

De categorieen waar je producten in zitten, met hun bovenliggende categorieen zodat je de boom kunt opbouwen. Met `locale`.

## Afrekenen

Een afnemer rekent af zoals elke zakelijke klant. Staat "op rekening" voor zijn
account aan, dan betaalt hij achteraf op factuur met de betaaltermijn die voor
hem is ingesteld. Openstaande facturen, herinneringen en een eventuele
kredietlimiet lopen via zijn klantaccount, niet via de API.

## Webhooks

Geef ons een https-adres, dan sturen we een `POST` bij elke wijziging.

```
POST <jouw adres>
Content-Type: application/json
X-Dashed-Event: product.updated
X-Dashed-Delivery: 9812
X-Dashed-Timestamp: 1758096000
X-Dashed-Signature: sha256=5d41402abc4b2a76b9719d911017c592...
```

```json
{ "id": 9812, "event": "product.updated", "created_at": "2026-09-17T10:15:00+02:00", "data": { ...zelfde vorm als /products met locale=all... } }
```

Gebeurtenissen: `product.updated`, `product.removed` (`data` is `{id, removed, changed_at}`) en `ping` (testbericht).

**Controleer de handtekening.** Bereken HMAC-SHA256 over `timestamp + "." + ruwe body` met je webhookgeheim, en vergelijk met de header. Weiger een bericht waarvan het tijdstip meer dan vijf minuten afwijkt.

```php
$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
$valid = hash_equals($expected, $signatureHeader) && abs(time() - (int) $timestamp) <= 300;
```

Antwoord binnen tien seconden met een 2xx. Anders proberen we het opnieuw na 1 minuut, 5 minuten, 30 minuten, 2 uur en 12 uur. Na twintig mislukte pogingen op rij zetten we de webhook uit; `updated_since` blijft dan werken. Doorverwijzingen volgen we niet. Een bericht kan dubbel aankomen; gebruik `X-Dashed-Delivery` om dat te herkennen.
