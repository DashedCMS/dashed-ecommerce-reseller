# Changelog

## v4.0.0 - 2026-09-21

### Added
- Afnemers-API: assortimenten, afnemersprofielen, sleutels, REST-endpoints, gematerialiseerde catalogus en webhooks bij wijzigingen.
- Vereist `dashed/dashed-core` ^4.71 (sleutelsoorten, verzoeklimieten en webhooktabellen) en `dashed/dashed-ecommerce-core` 4.136.0 of nieuwer (de PriceGroup-events), en conflicteert met `dashed/dashed-mobile-api` < 4.14.0 (oudere versies laten een afnemerssleutel bij de mobiele zoekfunctie).
- De ec-core-ondergrens staat onder `conflict` en niet onder `require`: in de monorepo komt ec-core als `dev-master` uit een path-repository zonder branch-alias, en daar matcht geen `^4.136` op.
- Vereist na uitrol: `php artisan migrate` en de scheduler (`reseller:reconcile` om 03:00).
