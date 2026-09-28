<?php

return [
    'api' => [
        'per_page_default' => 100,
        'per_page_max' => 250,
        'stock_ids_max' => 250,
        'rate_limit_default' => 300,
    ],

    'sync' => [
        // Porties bij het herberekenen van een hele catalogus.
        'chunk' => 500,
        'queue' => 'ecommerce',
        // Productwijzigingen wachten zo lang op elkaar voordat ze in één
        // job tegen de catalogus gaan.
        'debounce_seconds' => 30,
    ],

    'retention' => [
        'api_logs_days' => 30,
        'removed_items_days' => 30,
    ],

    'feeds' => [
        // Lokaal en privé: het bestand bevat inkoopprijzen.
        'disk' => 'local',
        // Een reeks wijzigingen levert één generatie op.
        'delay_seconds' => 300,
        'rate_limit_default' => 60,
    ],

    'currency' => 'EUR',
];
