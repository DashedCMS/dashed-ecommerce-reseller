<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommerceReseller\Http\Controllers\FeedController;
use Dashed\DashedEcommerceReseller\Http\Controllers\ApiDocsController;

// De bestandsnaam en niet alleen het formaat in de route: de twee
// importfeeds heten naar hun platform en zijn CSV, de open feeds heten
// products.json en products.xml. ResellerProfile::FEED_FILES is de enige
// plek waar die namen staan; deze route laat alleen de vorm door.
Route::get('reseller-feed/{token}/{file}', FeedController::class)
    ->where(['token' => '[A-Za-z0-9]{1,100}', 'file' => '[a-z]+\.(csv|json|xml)'])
    ->middleware('throttle:dashed-reseller-feed')
    ->name('dashed.reseller-feed.show');

// Publiek en ongeauthenticeerd: net als de feedroute zelf een verzoeklimiet,
// zodat het renderen van de documentatie (Str::markdown per aanvraag zonder
// cache) geen goedkope manier is om de server te belasten.
Route::get('reseller-api/docs', ApiDocsController::class)
    ->middleware('throttle:60,1')
    ->name('dashed.reseller-api.docs');
