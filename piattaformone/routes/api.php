<?php

use App\Http\Controllers\FattureInCloudOAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Fatture in Cloud OAuth routes
Route::prefix('fic/oauth')->group(function () {
    Route::get('/redirect', [FattureInCloudOAuthController::class, 'redirect'])
        ->name('fic.oauth.redirect');
    
    Route::get('/callback', [FattureInCloudOAuthController::class, 'callback'])
        ->name('fic.oauth.callback');
});

// Fatture in Cloud test and debug endpoints
Route::prefix('fic')->group(function () {
    Route::get('/status', [FattureInCloudOAuthController::class, 'status'])
        ->name('fic.status');
    Route::get('/debug', [FattureInCloudOAuthController::class, 'debug'])
        ->name('fic.debug');
    Route::get('/test', [FattureInCloudOAuthController::class, 'test'])
        ->name('fic.test');
});
