<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommerceReseller\Http\Controllers\MeController;
use Dashed\DashedEcommerceReseller\Http\Controllers\StockController;
use Dashed\DashedEcommerceReseller\Http\Controllers\ProductController;
use Dashed\DashedEcommerceReseller\Http\Controllers\CategoryController;
use Dashed\DashedEcommerceReseller\Http\Middleware\EnsureResellerAccess;
use Dashed\DashedEcommerceReseller\Http\Middleware\LogResellerApiRequest;
use Dashed\DashedEcommerceReseller\Http\Controllers\ProductGroupController;

Route::prefix('api/reseller/v1')
    ->middleware([
        LogResellerApiRequest::class,
        'auth:sanctum',
        EnsureResellerAccess::class,
        'throttle:dashed-reseller-api',
    ])
    ->name('dashed.reseller-api.')
    ->group(function (): void {
        Route::get('me', MeController::class)->name('me');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');
        Route::get('stock', StockController::class)->name('stock');
        Route::get('product-groups', [ProductGroupController::class, 'index'])->name('product-groups.index');
        Route::get('categories', CategoryController::class)->name('categories');

        // Alles wat hier niet bestaat krijgt de eigen foutvorm, ook met een
        // geldige sleutel. Geen Route::fallback(): die heeft de laagste
        // prioriteit van de hele applicatie en verliest van de front-end
        // catch-all-route van dashed-core, prefix of niet.
        Route::any('{any}', fn () => \Dashed\DashedEcommerceReseller\Http\ApiError::response('not_found', 'Niet gevonden.', 404))
            ->where('any', '.*')
            ->name('not-found');
    });
