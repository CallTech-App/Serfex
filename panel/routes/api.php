<?php

use App\Http\Controllers\Api\AppApiController;
use App\Http\Controllers\Api\CliLoginController;
use App\Http\Controllers\Api\DeployApiController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\Panel\BackupsController;
use App\Http\Controllers\Api\Panel\CertificatesController;
use App\Http\Controllers\Api\Panel\CustomersController;
use App\Http\Controllers\Api\Panel\DatabasesController;
use App\Http\Controllers\Api\Panel\DnsController;
use App\Http\Controllers\Api\Panel\DomainsController;
use App\Http\Controllers\Api\Panel\MailController;
use App\Http\Controllers\Api\Panel\PackagesController;
use App\Http\Controllers\Api\Panel\ServerController;
use App\Http\Controllers\Api\Panel\ServicesController;
use App\Http\Controllers\Api\Panel\SitesController;
use App\Http\Middleware\ApiLocale;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateAppAccess;
use App\Http\Middleware\EnsureApiFeature;
use Illuminate\Support\Facades\Route;

$domain = '[A-Za-z0-9.-]+';
$key = fn (string $who) => AuthenticateApiKey::class.':'.$who;
$app = fn (string $ability, ?string $feature = null) => AuthenticateAppAccess::class.':'.$ability.($feature ? ','.$feature : '');
$feature = fn (string $name) => EnsureApiFeature::class.':'.$name;

Route::prefix('v1')->middleware(ApiLocale::class)->group(function () use ($domain, $key, $app, $feature) {
    // The serfex command line signs in through the browser: it asks for a
    // sign-in, the person approves it at /cli/onay, the command line
    // collects its key.
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/cli/login', [CliLoginController::class, 'start'])->name('api.cli.login');
        Route::post('/cli/login/token', [CliLoginController::class, 'collect'])->name('api.cli.token');
    });

    // Who a key or an application token belongs to; serfex logout closes
    // its own command line key.
    Route::get('/me', [MeController::class, 'show'])->middleware('throttle:60,1')->name('api.me');
    Route::delete('/me', [MeController::class, 'destroy'])->middleware('throttle:60,1');

    // Laravel applications: for an application's own token (CI) or a
    // person's key, within the person's role on the application.
    Route::get('/apps', [AppApiController::class, 'index'])->middleware($key('any'))->name('api.apps');
    Route::prefix('apps/{domain}')->where(['domain' => $domain])->group(function () use ($app) {
        Route::get('/', [DeployApiController::class, 'show'])->middleware($app('view'))->name('api.app');
        Route::post('/deploy', [DeployApiController::class, 'deploy'])->middleware($app('deploy'))->name('api.deploy');
        Route::get('/deployments', [DeployApiController::class, 'deployments'])->middleware($app('view'))->name('api.deployments');
        Route::get('/deployments/{number}', [DeployApiController::class, 'showDeployment'])->whereNumber('number')->middleware($app('view'))->name('api.deployment');
        Route::post('/rollback', [DeployApiController::class, 'rollback'])->middleware($app('deploy'))->name('api.rollback');
        Route::post('/previews', [DeployApiController::class, 'createPreview'])->middleware($app('deploy'))->name('api.previews.create');
        Route::delete('/previews/{name}', [DeployApiController::class, 'deletePreview'])->where('name', '[a-z0-9-]+')->middleware($app('deploy'))->name('api.previews.delete');
        Route::get('/runs/{run}', [AppApiController::class, 'run'])->where('run', '[a-z0-9-]+')->middleware($app('view'))->name('api.app.run');
        Route::get('/env', [AppApiController::class, 'env'])->middleware($app('operate'))->name('api.app.env');
        Route::put('/env', [AppApiController::class, 'replaceEnv'])->middleware($app('operate'));
        Route::patch('/env', [AppApiController::class, 'changeEnv'])->middleware($app('operate'));
        Route::post('/commands', [AppApiController::class, 'runCommand'])->middleware($app('operate', 'shell'))->name('api.app.commands');
        Route::get('/commands/{id}', [AppApiController::class, 'showCommand'])->whereNumber('id')->middleware($app('view'))->name('api.app.command');
        Route::get('/queues', [AppApiController::class, 'queues'])->middleware($app('view'))->name('api.app.queues');
        Route::get('/queues/failed', [AppApiController::class, 'failedJobs'])->middleware($app('view'));
        Route::post('/queues/restart', [AppApiController::class, 'restartQueues'])->middleware($app('operate'));
        Route::post('/queues/retry', [AppApiController::class, 'retryJobs'])->middleware($app('operate'));
        Route::get('/maintenance', [AppApiController::class, 'maintenance'])->middleware($app('view'))->name('api.app.maintenance');
        Route::post('/maintenance', [AppApiController::class, 'setMaintenance'])->middleware($app('operate'));
        Route::get('/logs', [AppApiController::class, 'logs'])->middleware($app('view'))->name('api.app.logs');
    });

    // The server, customers, packages and services: administrators only.
    // A panel key (API anahtarları) acts as the full administrator who
    // made it; a command line key as its person, read-only for read-only
    // administrators.
    Route::middleware($key('admin'))->group(function () {
        Route::get('/server', [ServerController::class, 'show'])->name('api.server');
        Route::get('/customers', [CustomersController::class, 'index'])->name('api.customers');
        Route::post('/customers', [CustomersController::class, 'store']);
        Route::get('/customers/{customer}', [CustomersController::class, 'show'])->whereNumber('customer')->name('api.customer');
        Route::post('/customers/{customer}/suspend', [CustomersController::class, 'suspend'])->whereNumber('customer');
        Route::post('/customers/{customer}/unsuspend', [CustomersController::class, 'unsuspend'])->whereNumber('customer');
        Route::get('/packages', [PackagesController::class, 'index'])->name('api.packages');
        Route::get('/services', [ServicesController::class, 'index'])->name('api.services');
        Route::get('/services/{unit}', [ServicesController::class, 'show'])->where('unit', '[A-Za-z0-9@._-]+')->name('api.service');
        Route::get('/services/{unit}/logs', [ServicesController::class, 'logs'])->where('unit', '[A-Za-z0-9@._-]+');
        Route::post('/services/{unit}/{action}', [ServicesController::class, 'act'])->where(['unit' => '[A-Za-z0-9@._-]+', 'action' => 'start|stop|restart']);
    });

    // Hosting: administrators everything, customers their own sites within
    // their package.
    Route::middleware($key('account'))->group(function () use ($domain, $feature) {
        Route::get('/sites', [SitesController::class, 'index'])->name('api.sites');
        Route::post('/sites', [SitesController::class, 'store']);
        Route::get('/sites/{domain}', [SitesController::class, 'show'])->where('domain', $domain)->name('api.site');
        Route::delete('/sites/{domain}', [SitesController::class, 'destroy'])->where('domain', $domain);

        Route::get('/sites/{domain}/domains', [DomainsController::class, 'index'])->where('domain', $domain)->name('api.site.domains');
        Route::post('/sites/{domain}/subdomains', [DomainsController::class, 'storeSubdomain'])->where('domain', $domain);
        Route::post('/sites/{domain}/aliases', [DomainsController::class, 'storeAlias'])->where('domain', $domain);
        Route::post('/sites/{domain}/redirects', [DomainsController::class, 'storeRedirect'])->where('domain', $domain);
        Route::delete('/sites/{domain}/domains', [DomainsController::class, 'destroy'])->where('domain', $domain);

        Route::get('/certificates', [CertificatesController::class, 'index'])->name('api.certificates');
        Route::get('/sites/{domain}/ssl', [CertificatesController::class, 'show'])->where('domain', $domain)->name('api.site.ssl');
        Route::post('/sites/{domain}/ssl', [CertificatesController::class, 'issue'])->where('domain', $domain);
        Route::post('/sites/{domain}/ssl/renew', [CertificatesController::class, 'renew'])->where('domain', $domain);
        Route::put('/sites/{domain}/ssl/https', [CertificatesController::class, 'https'])->where('domain', $domain);

        Route::middleware($feature('dns'))->group(function () use ($domain) {
            Route::get('/dns/zones', [DnsController::class, 'index'])->name('api.zones');
            Route::get('/dns/zones/{zone}', [DnsController::class, 'show'])->where('zone', $domain)->name('api.zone');
            Route::post('/dns/zones/{zone}/records', [DnsController::class, 'storeRecord'])->where('zone', $domain);
            Route::delete('/dns/zones/{zone}/records/{record}', [DnsController::class, 'destroyRecord'])->where('zone', $domain)->whereNumber('record');
        });

        Route::middleware($feature('mail'))->group(function () use ($domain) {
            Route::get('/mail/domains', [MailController::class, 'index'])->name('api.mail');
            Route::get('/mail/domains/{domain}', [MailController::class, 'show'])->where('domain', $domain)->name('api.mail.domain');
            Route::post('/mail/domains', [MailController::class, 'enable']);
            Route::post('/mail/domains/{domain}/mailboxes', [MailController::class, 'storeMailbox'])->where('domain', $domain);
            Route::patch('/mail/domains/{domain}/mailboxes/{name}', [MailController::class, 'updateMailbox'])->where(['domain' => $domain, 'name' => '[a-z0-9._-]+']);
            Route::delete('/mail/domains/{domain}/mailboxes/{name}', [MailController::class, 'destroyMailbox'])->where(['domain' => $domain, 'name' => '[a-z0-9._-]+']);
            Route::put('/mail/domains/{domain}/forwarders/{name}', [MailController::class, 'saveForwarder'])->where(['domain' => $domain, 'name' => '[a-z0-9._*-]+']);
            Route::delete('/mail/domains/{domain}/forwarders/{name}', [MailController::class, 'destroyForwarder'])->where(['domain' => $domain, 'name' => '[a-z0-9._*-]+']);
        });

        Route::get('/databases', [DatabasesController::class, 'index'])->name('api.databases');
        Route::post('/databases', [DatabasesController::class, 'store']);
        Route::get('/databases/{name}', [DatabasesController::class, 'show'])->where('name', '[a-z0-9_]+')->name('api.database');
        Route::delete('/databases/{name}', [DatabasesController::class, 'destroy'])->where('name', '[a-z0-9_]+');

        Route::middleware($feature('backups'))->group(function () {
            Route::get('/backups', [BackupsController::class, 'index'])->name('api.backups');
            Route::post('/backups', [BackupsController::class, 'store']);
            Route::get('/backups/{backup}', [BackupsController::class, 'show'])->whereNumber('backup')->name('api.backup');
            Route::post('/backups/{backup}/restore', [BackupsController::class, 'restore'])->whereNumber('backup');
            Route::get('/restores/{restore}', [BackupsController::class, 'showRestore'])->whereNumber('restore')->name('api.restore');
        });
    });
});
