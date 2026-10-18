<?php

use App\Http\Controllers\Auth\LoginController;
use App\Livewire\Dns\DnsSettingsPage;
use App\Livewire\Dns\ZoneIndex;
use App\Livewire\Dns\ZoneShow;
use App\Livewire\Sites\SiteIndex;
use App\Livewire\Sites\SiteShow;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::view('/', 'dashboard')->name('dashboard');
    Route::livewire('/sites', SiteIndex::class)->name('sites.index');
    Route::livewire('/sites/{site}', SiteShow::class)->whereNumber('site')->name('sites.show');
    Route::livewire('/dns', ZoneIndex::class)->name('dns.index');
    Route::livewire('/dns/settings', DnsSettingsPage::class)->name('dns.settings');
    Route::livewire('/dns/{zone}', ZoneShow::class)->whereNumber('zone')->name('dns.show');
});
