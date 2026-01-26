<?php

use App\Http\Controllers\FattureInCloudOAuthController;
use App\Http\Controllers\FicDocumentController;
use App\Http\Controllers\FicSubscriptionController;
use App\Http\Controllers\FicSyncController;
use App\Http\Controllers\FicWebhookController;
use App\Http\Controllers\WebhookController;
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

// Fatture in Cloud Webhook endpoint
// Supports both GET (subscription verification) and POST (notifications)
Route::match(['get', 'post'], '/webhooks/fattureincloud', [WebhookController::class, 'handle'])
    ->name('webhooks.fattureincloud');

// Fatture in Cloud Multi-Tenant Webhook endpoints
// Dynamic routes with account_id and event_group parameters
// Supports both GET (subscription verification) and POST (notifications)
// Rate limited to 1 request per second per IP
Route::match(['get', 'post'], '/webhooks/fic/{account_id}/{group}', [FicWebhookController::class, 'handle'])
    ->where(['account_id' => '[0-9]+', 'group' => '[a-z_]+'])
    ->middleware('throttle:fic-webhook')
    ->name('webhooks.fic.handle');

// Fatture in Cloud Sync and Dashboard API endpoints
Route::prefix('fic')->group(function () {
    Route::post('/initial-sync', [FicSyncController::class, 'initialSync'])
        ->name('fic.initial-sync');
    Route::get('/events', [FicSyncController::class, 'events'])
        ->name('fic.events');
    Route::get('/metrics', [FicSyncController::class, 'metrics'])
        ->name('fic.metrics');

    // Synced data endpoints
    Route::get('/clients', [FicSyncController::class, 'clients'])
        ->name('fic.clients');
    Route::get('/suppliers', [FicSyncController::class, 'suppliers'])
        ->name('fic.suppliers');
    Route::get('/quotes', [FicSyncController::class, 'quotes'])
        ->name('fic.quotes');
    Route::get('/invoices', [FicSyncController::class, 'invoices'])
        ->name('fic.invoices');
});

// Fatture in Cloud Subscription endpoints
Route::prefix('fic/subscriptions')->group(function () {
    Route::get('/accounts', [FicSubscriptionController::class, 'accounts'])
        ->name('fic.subscriptions.accounts');
    Route::get('/', [FicSubscriptionController::class, 'list'])
        ->name('fic.subscriptions.list');
    Route::post('/', [FicSubscriptionController::class, 'store'])
        ->name('fic.subscriptions.store');
});

// Fatture in Cloud Document generation endpoints
Route::prefix('fic/documents')->group(function () {
    Route::get('/data', [FicDocumentController::class, 'getData'])
        ->name('fic.documents.data');
    Route::get('/resource', [FicDocumentController::class, 'getResourceData'])
        ->name('fic.documents.resource');
    Route::post('/extract-variables', [FicDocumentController::class, 'extractVariables'])
        ->name('fic.documents.extract-variables');
    Route::post('/compile', [FicDocumentController::class, 'compile'])
        ->name('fic.documents.compile');
    Route::post('/compile-mapping', [FicDocumentController::class, 'compileWithMapping'])
        ->name('fic.documents.compile-mapping');
    Route::post('/compile-batch', [FicDocumentController::class, 'compileBatch'])
        ->name('fic.documents.compile-batch');
});
