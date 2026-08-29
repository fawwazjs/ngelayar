<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| NGELAYAR Web Routes (Inertia)
|--------------------------------------------------------------------------
| "/"      -> Welcome (info Phase 1 & 2)
| "/map"   -> Map interaktif (akan diisi NgelayarMap.jsx di Phase 4)
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'appName' => config('app.name', 'NGELAYAR'),
    ]);
})->name('home');

Route::get('/map', function () {
    return Inertia::render('Map/Index');
})->name('map.index');

// Health check untuk deployment / monitoring
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'version' => '0.1.0-phase1+2',
        'time' => now()->toIso8601String(),
    ]);
});
