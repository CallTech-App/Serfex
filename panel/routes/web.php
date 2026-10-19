<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Laravel\BackupDownloadController;
use App\Http\Controllers\Laravel\DeployWebhookController;
use App\Http\Controllers\Mail\WebmailController;
use App\Http\Middleware\EnsureAdmin;
use App\Livewire\Dns\DnsSettingsPage;
use App\Livewire\Dns\ZoneIndex;
use App\Livewire\Dns\ZoneShow;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\LaravelShow;
use App\Livewire\Mail\MailDomainShow;
use App\Livewire\Mail\MailIndex;
use App\Livewire\Mail\MailSettingsPage;
use App\Livewire\Monitor\LiveMonitor;
use App\Livewire\Sites\SiteIndex;
use App\Livewire\Sites\SiteShow;
use App\Livewire\Ssl\SiteSsl;
use App\Livewire\Ssl\SslIndex;
use App\Livewire\Ssl\SslSettingsPage;
use Illuminate\Support\Facades\Route;

Route::post('/hooks/deploy/{token}', DeployWebhookController::class)
    ->where('token', '[A-Za-z0-9]+')->middleware('throttle:30,1')->name('laravel.webhook');

Route::get('/davet/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{40,80}')->middleware('throttle:30,1')->name('invitation');
Route::post('/davet/{token}', [InvitationController::class, 'store'])->where('token', '[A-Za-z0-9]{40,80}')->middleware('throttle:10,1');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Laravel pages are also for members of applications; each page
    // checks the account's role on the application.
    Route::livewire('/laravel', LaravelIndex::class)->name('laravel.index');
    Route::livewire('/laravel/{app}', LaravelShow::class)->whereNumber('app')->name('laravel.show');
    Route::get('/laravel/{app}/yedekler/{backup}', BackupDownloadController::class)->whereNumber(['app', 'backup'])->name('laravel.backup.download');

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::view('/', 'dashboard')->name('dashboard');
        Route::livewire('/monitor', LiveMonitor::class)->name('monitor.index');
        Route::livewire('/sites', SiteIndex::class)->name('sites.index');
        Route::livewire('/sites/{site}', SiteShow::class)->whereNumber('site')->name('sites.show');
        Route::livewire('/sites/{site}/ssl', SiteSsl::class)->whereNumber('site')->name('sites.ssl');
        Route::livewire('/dns', ZoneIndex::class)->name('dns.index');
        Route::livewire('/dns/settings', DnsSettingsPage::class)->name('dns.settings');
        Route::livewire('/dns/{zone}', ZoneShow::class)->whereNumber('zone')->name('dns.show');
        Route::livewire('/mail', MailIndex::class)->name('mail.index');
        Route::livewire('/mail/settings', MailSettingsPage::class)->name('mail.settings');
        Route::livewire('/mail/{domain}', MailDomainShow::class)->whereNumber('domain')->name('mail.show');
        Route::get('/mail/mailboxes/{mailbox}/webmail', WebmailController::class)->whereNumber('mailbox')->name('mail.webmail');
        Route::livewire('/ssl', SslIndex::class)->name('ssl.index');
        Route::livewire('/ssl/settings', SslSettingsPage::class)->name('ssl.settings');
    });
});
