# Changelog

## v4.1.0 - 2026-09-22

### Added
- Feeds voor Shopify (Matrixify) en WooCommerce (WP All Import): per afnemer een versleuteld feedtoken (plus hash om op te zoeken) en twee vooraf gegenereerde CSV-bestanden op `/reseller-feed/{token}/{shopify|woocommerce}.csv`. Prijzen, voorraad en inhoud komen uit dezelfde bron als de API.
- `GenerateResellerFeedsJob` schrijft beide bestanden atomisch, uniek per profiel en vertraagd, na elke synchronisatie die iets veranderde en na `reseller:reconcile`.
- Installatiemail `ResellerSetupMail` met stappen per platform in gewone taal (`SetupInstructions`), als mailsjabloon aanpasbaar, `ContainsSecrets` zodat de body niet in het logboek komt, en opgebouwd in de taal van de site van de afnemer.
- Knoppen "Installatiemail sturen" en "Nieuwe feedsleutel" op de afnemerpagina, plus een sectie met de feedlinks, wanneer ze bijgewerkt en opgehaald zijn, en dezelfde stappen als in de mail.
- Publieke documentatiepagina `/reseller-api/docs`; de afnemersdocumentatie verhuisde naar `resources/docs/reseller-api.md` zodat klantprojecten hem meekrijgen.

### Fixed
- `ContextScope` zette `dashed-core.dashed_site_id` bij het herstellen op `null` in plaats van de sleutel weg te laten, waardoor `Sites::getActive()` na één afnemersjob de rest van het leven van een queue-worker niets meer teruggaf.

### Security
- De feedroute genereert nooit in het verzoek zelf: een fout daar zou de URL met het rauwe token naar de foutrapportage sturen. Ontbreekt het bestand, dan volgt een 503 met `Retry-After` en gaat het genereren naar de wachtrij (en op een `sync`-wachtrij helemaal niet).
- Het token staat nooit in het logboek (`/reseller-feed/***/...`), in het activiteitenlog of in een queue-payload; de CSV draagt `Cache-Control: private, no-store`; `/reseller-feed/` staat in robots.txt; onbekend, uitgezet en onbekend formaat geven alle drie een 404.
- Feedbestanden worden verwijderd zodra het afnemersprofiel verdwijnt.

### Vereist na uitrol
- `php artisan migrate` (feedtoken op bestaande afnemers) en een draaiende queue-worker die dezelfde schijf ziet als de webserver.

## v4.0.0 - 2026-09-21

### Added
- Afnemers-API: assortimenten, afnemersprofielen, sleutels, REST-endpoints, gematerialiseerde catalogus en webhooks bij wijzigingen.
- Vereist `dashed/dashed-core` ^4.71 (sleutelsoorten, verzoeklimieten en webhooktabellen) en `dashed/dashed-ecommerce-core` 4.136.0 of nieuwer (de PriceGroup-events), en conflicteert met `dashed/dashed-mobile-api` < 4.14.0 (oudere versies laten een afnemerssleutel bij de mobiele zoekfunctie).
- De ec-core-ondergrens staat onder `conflict` en niet onder `require`: in de monorepo komt ec-core als `dev-master` uit een path-repository zonder branch-alias, en daar matcht geen `^4.136` op.
- Vereist na uitrol: `php artisan migrate` en de scheduler (`reseller:reconcile` om 03:00).
