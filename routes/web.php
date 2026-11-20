<?php

declare(strict_types=1);

use BadRush\Weathermap\Http\Controllers\AssetController;
use BadRush\Weathermap\Http\Controllers\BundleController;
use BadRush\Weathermap\Http\Controllers\ImageController;
use BadRush\Weathermap\Http\Controllers\ImportController;
use BadRush\Weathermap\Http\Controllers\LookupController;
use BadRush\Weathermap\Http\Controllers\MapController;
use BadRush\Weathermap\Http\Controllers\SceneController;
use Illuminate\Support\Facades\Route;

// Static Vite bundle & bundled fonts served from package dist/ (no vendor:publish needed)
Route::get('/weathermap/assets/{path}', [BundleController::class, 'show'])
    ->where('path', '.*')
    ->name('weathermap.bundle');

// Authenticated web & JSON API endpoints
Route::middleware(['web', 'auth'])->prefix('weathermap')->name('weathermap.')->group(function (): void {
    // Pages
    Route::get('/', [MapController::class, 'index'])->name('index');
    Route::get('/kiosk', [MapController::class, 'kiosk'])->name('kiosk');
    Route::get('/maps/{map}.png', [ImageController::class, 'png'])->name('maps.png');
    Route::get('/maps/{map}/thumb.png', [ImageController::class, 'thumb'])->name('maps.thumb');
    Route::get('/maps/{map}/edit', [MapController::class, 'edit'])->name('maps.edit');
    Route::get('/maps/{map}', [MapController::class, 'show'])->name('maps.show');

    // JSON API
    Route::prefix('api')->name('api.')->group(function (): void {
        Route::get('/maps', [MapController::class, 'apiIndex'])->name('maps.index');
        Route::post('/maps', [MapController::class, 'apiStore'])->name('maps.store');
        Route::get('/maps/{map}', [MapController::class, 'apiShow'])->name('maps.show');
        Route::put('/maps/{map}', [MapController::class, 'apiUpdate'])->name('maps.update');
        Route::delete('/maps/{map}', [MapController::class, 'apiDestroy'])->name('maps.destroy');
        Route::put('/maps/{map}/access', [MapController::class, 'apiUpdateAccess'])->name('maps.access');
        Route::get('/maps/{map}/scene', [SceneController::class, 'show'])
            ->middleware('throttle:120,1')
            ->name('maps.scene');

        Route::get('/lookup/devices', [LookupController::class, 'devices'])
            ->middleware('throttle:60,1')
            ->name('lookup.devices');
        Route::get('/lookup/ports', [LookupController::class, 'ports'])
            ->middleware('throttle:60,1')
            ->name('lookup.ports');
        Route::get('/lookup/subjects', [LookupController::class, 'subjects'])
            ->middleware('throttle:30,1')
            ->name('lookup.subjects');

        Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
        Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
        Route::post('/assets/sync-vendors', [AssetController::class, 'syncVendors'])->name('assets.sync-vendors');
        Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');

        Route::post('/import', [ImportController::class, 'store'])->name('import');
    });
});
