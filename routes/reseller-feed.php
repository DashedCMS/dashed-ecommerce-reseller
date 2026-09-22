<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommerceReseller\Http\Controllers\FeedController;
use Dashed\DashedEcommerceReseller\Http\Controllers\ApiDocsController;

Route::get('reseller-feed/{token}/{format}.csv', FeedController::class)
    ->where(['token' => '[A-Za-z0-9]{1,100}', 'format' => '[a-z]+'])
    ->middleware('throttle:dashed-reseller-feed')
    ->name('dashed.reseller-feed.show');

// Publiek en ongeauthenticeerd: net als de feedroute zelf een verzoeklimiet,
// zodat het renderen van de documentatie (Str::markdown per aanvraag zonder
// cache) geen goedkope manier is om de server te belasten.
Route::get('reseller-api/docs', ApiDocsController::class)
    ->middleware('throttle:60,1')
    ->name('dashed.reseller-api.docs');
