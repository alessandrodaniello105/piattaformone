<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Debug route to check headers
Route::get('/debug-headers', function () {
    return response()->json([
        'app_url' => config('app.url'),
        'request_url' => request()->url(),
        'request_root' => request()->root(),
        'host' => request()->getHost(),
        'headers' => [
            'Host' => request()->header('Host'),
            'X-Forwarded-Host' => request()->header('X-Forwarded-Host'),
            'X-Forwarded-Proto' => request()->header('X-Forwarded-Proto'),
            'X-Forwarded-For' => request()->header('X-Forwarded-For'),
            'X-Forwarded-Port' => request()->header('X-Forwarded-Port'),
        ],
        'is_secure' => request()->isSecure(),
        'server_port' => request()->getPort(),
    ]);
});

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/fic/subscriptions/create', function () {
        return Inertia::render('Fic/CreateSubscription');
    })->name('fic.subscriptions.create');

    Route::get('/fic/data', function () {
        return Inertia::render('Fic/SyncedData');
    })->name('fic.data');

    Route::get('/fic/documents/generate', [App\Http\Controllers\FicDocumentController::class, 'index'])
        ->name('fic.documents.generate');
});
