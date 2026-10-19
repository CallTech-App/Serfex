<?php

use App\Http\Controllers\Api\DeployApiController;
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
