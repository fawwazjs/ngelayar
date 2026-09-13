<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\OceanDataController;

/*
|--------------------------------------------------------------------------
| NGELAYAR API Routes — v1
|--------------------------------------------------------------------------
| Prefix: /api/v1
| Semua response GeoJSON FeatureCollection (Leaflet ready)
| X-Data-Source header: mock | db-cache | ml-live (untuk debugging)
*/

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// === Ocean Data (ZPPI & Hazard) — Plug-and-play ML ===
Route::prefix('v1/ocean-data')->name('api.v1.ocean-data.')->group(function () {
    // GET /api/v1/ocean-data/zppi?lat=&lng=&radius_km=&prob_min=
    Route::get('/zppi', [OceanDataController::class, 'zppi'])->name('zppi');

    // GET /api/v1/ocean-data/hazard?lat=&lng=&radius_km=&level=&active_only=
    Route::get('/hazard', [OceanDataController::class, 'hazard'])->name('hazard');

    // GET /api/v1/ocean-data/noaa-status
    Route::get('/noaa-status', [OceanDataController::class, 'noaaStatus'])->name('noaa-status');
});

// Health untuk API (dipakai PWA offline check)
Route::get('/v1/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'ngelayar-api',
        'version' => '1.0-phase3',
        'time' => now()->toIso8601String(),
    ]);
});
