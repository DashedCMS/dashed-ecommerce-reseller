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
    ],

    'retention' => [
        'api_logs_days' => 30,
        'removed_items_days' => 30,
    ],

    'currency' => 'EUR',
];
