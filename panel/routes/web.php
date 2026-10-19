<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Backups\BackupDownloadController as ServerBackupDownloadController;
use App\Http\Controllers\Backups\BackupUploadController;
use App\Http\Controllers\Customers\ImpersonationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Databases\DatabaseManagerController;
use App\Http\Controllers\Laravel\BackupDownloadController;
use App\Http\Controllers\Laravel\DeployWebhookController;
use App\Http\Controllers\Mail\WebmailController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureHostingAccess;
use App\Livewire\Ai\AiAssistant;
use App\Livewire\Ai\AiAudit;
use App\Livewire\Ai\AiSettingsPage;
use App\Livewire\Backups\BackupIndex;
use App\Livewire\Components\ComponentIndex;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Databases\DatabaseIndex;
use App\Livewire\Databases\DatabaseSettingsPage;
use App\Livewire\Databases\DatabaseShow;
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
use App\Livewire\Sites\SitePhp;
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

Route::middleware(['auth', EnsureActiveAccount::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/yoneticiye-don', [ImpersonationController::class, 'destroy'])->name('impersonation.leave');

    // Laravel pages are also for members of applications; each page
    // checks the account's role on the application.
    Route::livewire('/laravel', LaravelIndex::class)->name('laravel.index');
    Route::livewire('/laravel/{app}', LaravelShow::class)->whereNumber('app')->name('laravel.show');
    Route::get('/laravel/{app}/yedekler/{backup}', BackupDownloadController::class)->whereNumber(['app', 'backup'])->name('laravel.backup.download');

    // Hosting pages: administrators see everything, customers their own.
    Route::middleware(EnsureHostingAccess::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::livewire('/sites', SiteIndex::class)->name('sites.index');
        Route::livewire('/sites/{site}', SiteShow::class)->whereNumber('site')->name('sites.show');
        Route::livewire('/sites/{site}/ssl', SiteSsl::class)->whereNumber('site')->name('sites.ssl');
        Route::livewire('/sites/{site}/php', SitePhp::class)->whereNumber('site')->name('sites.php');
        Route::livewire('/dns', ZoneIndex::class)->name('dns.index');
        Route::livewire('/dns/{zone}', ZoneShow::class)->whereNumber('zone')->name('dns.show');
        Route::livewire('/mail', MailIndex::class)->name('mail.index');
        Route::livewire('/mail/{domain}', MailDomainShow::class)->whereNumber('domain')->name('mail.show');
        Route::get('/mail/mailboxes/{mailbox}/webmail', WebmailController::class)->whereNumber('mailbox')->name('mail.webmail');
        Route::livewire('/ssl', SslIndex::class)->name('ssl.index');
        Route::livewire('/databases', DatabaseIndex::class)->name('databases.index');
        Route::livewire('/databases/{database}', DatabaseShow::class)->whereNumber('database')->name('databases.show');
        Route::get('/databases/{database}/yonetici', DatabaseManagerController::class)->whereNumber('database')->middleware('throttle:30,1')->name('databases.manager');
        Route::livewire('/yedekler', BackupIndex::class)->name('backups.index');
        Route::get('/yedekler/arsiv/{item}', ServerBackupDownloadController::class)->whereNumber('item')->name('backups.download');
    });

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::livewire('/monitor', LiveMonitor::class)->name('monitor.index');
        Route::livewire('/ai', AiAssistant::class)->name('ai.index');
        Route::livewire('/ai/settings', AiSettingsPage::class)->name('ai.settings');
        Route::livewire('/ai/kayit', AiAudit::class)->name('ai.audit');
        Route::livewire('/dns/settings', DnsSettingsPage::class)->name('dns.settings');
        Route::livewire('/mail/settings', MailSettingsPage::class)->name('mail.settings');
        Route::livewire('/ssl/settings', SslSettingsPage::class)->name('ssl.settings');
        Route::livewire('/databases/settings', DatabaseSettingsPage::class)->name('databases.settings');
        Route::livewire('/yazilim', ComponentIndex::class)->name('components.index');
        Route::post('/yedekler/yukle/{upload}', BackupUploadController::class)->where('upload', 'up-[a-z0-9]{20}')->name('backups.upload');
        Route::livewire('/musteriler', CustomerIndex::class)->name('customers.index');
        Route::livewire('/musteriler/{customer}', CustomerShow::class)->whereNumber('customer')->name('customers.show');
        Route::post('/musteriler/{customer}/giris', [ImpersonationController::class, 'store'])->whereNumber('customer')->name('customers.impersonate');
    });
});
