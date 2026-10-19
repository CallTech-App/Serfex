<?php

use App\Http\Controllers\Api\DeployApiController;
use App\Http\Controllers\Api\Panel\CustomersController;
use App\Http\Controllers\Api\Panel\DnsController;
use App\Http\Controllers\Api\Panel\PackagesController;
use App\Http\Controllers\Api\Panel\ServerController;
use App\Http\Controllers\Api\Panel\SitesController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateDeployToken;
use Illuminate\Support\Facades\Route;

// Deploy API for CI and the command line. A token works only for its own
// application, named by its domain.
Route::prefix('v1/apps/{domain}')
    ->where(['domain' => '[A-Za-z0-9.-]+'])
    ->middleware([AuthenticateDeployToken::class, 'throttle:60,1'])
    ->group(function () {
        Route::get('/', [DeployApiController::class, 'show'])->name('api.app');
        Route::post('/deploy', [DeployApiController::class, 'deploy'])->name('api.deploy');
        Route::get('/deployments/{number}', [DeployApiController::class, 'showDeployment'])->whereNumber('number')->name('api.deployment');
        Route::post('/previews', [DeployApiController::class, 'createPreview'])->name('api.previews.create');
        Route::delete('/previews/{name}', [DeployApiController::class, 'deletePreview'])->where('name', '[a-z0-9-]+')->name('api.previews.delete');
    });

// Panel API for billing systems, scripts and monitoring. A key acts as
// the full administrator who made it (Araçlar ve Ayarlar → API anahtarları);
// a read-only key may only GET.
Route::prefix('v1')
    ->middleware(AuthenticateApiKey::class)
    ->group(function () {
        Route::get('/server', [ServerController::class, 'show'])->name('api.server');
        Route::get('/sites', [SitesController::class, 'index'])->name('api.sites');
        Route::post('/sites', [SitesController::class, 'store']);
        Route::get('/sites/{domain}', [SitesController::class, 'show'])->where('domain', '[A-Za-z0-9.-]+')->name('api.site');
        Route::delete('/sites/{domain}', [SitesController::class, 'destroy'])->where('domain', '[A-Za-z0-9.-]+');
        Route::get('/customers', [CustomersController::class, 'index'])->name('api.customers');
        Route::post('/customers', [CustomersController::class, 'store']);
        Route::get('/customers/{customer}', [CustomersController::class, 'show'])->whereNumber('customer')->name('api.customer');
        Route::post('/customers/{customer}/suspend', [CustomersController::class, 'suspend'])->whereNumber('customer');
        Route::post('/customers/{customer}/unsuspend', [CustomersController::class, 'unsuspend'])->whereNumber('customer');
        Route::get('/packages', [PackagesController::class, 'index'])->name('api.packages');
        Route::get('/dns/zones', [DnsController::class, 'index'])->name('api.zones');
        Route::get('/dns/zones/{zone}', [DnsController::class, 'show'])->where('zone', '[A-Za-z0-9.-]+')->name('api.zone');
        Route::post('/dns/zones/{zone}/records', [DnsController::class, 'storeRecord'])->where('zone', '[A-Za-z0-9.-]+');
        Route::delete('/dns/zones/{zone}/records/{record}', [DnsController::class, 'destroyRecord'])->where('zone', '[A-Za-z0-9.-]+')->whereNumber('record');
    });
