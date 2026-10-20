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
use App\Http\Controllers\Settings\UpdateProgressController;
use App\Http\Controllers\Sites\SiteFileController;
use App\Http\Controllers\Sites\SiteLogController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureFullAdmin;
use App\Http\Middleware\EnsureHostingAccess;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RefuseReadOnlyChanges;
use App\Livewire\Account\AccountSecurity;
use App\Livewire\Ai\AiAssistant;
use App\Livewire\Ai\AiAudit;
use App\Livewire\Ai\AiSettingsPage;
use App\Livewire\Backups\BackupIndex;
use App\Livewire\Components\ComponentIndex;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Customers\UsageReport;
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
use App\Livewire\Security\SecurityIndex;
use App\Livewire\Services\PhpVersionShow;
use App\Livewire\Services\ServiceIndex;
use App\Livewire\Services\ServiceShow;
use App\Livewire\Settings\AdminIndex;
use App\Livewire\Settings\ApiKeyIndex;
use App\Livewire\Settings\AuditLogPage;
use App\Livewire\Settings\GeneralSettings;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\ServerSettings;
use App\Livewire\Settings\UpdatesPage;
use App\Livewire\Sites\SiteCron;
use App\Livewire\Sites\SiteDirectives;
use App\Livewire\Sites\SiteDisk;
use App\Livewire\Sites\SiteDomainList;
use App\Livewire\Sites\SiteErrorPages;
use App\Livewire\Sites\SiteFiles;
use App\Livewire\Sites\SiteFtp;
use App\Livewire\Sites\SiteIndex;
use App\Livewire\Sites\SiteLogs;
use App\Livewire\Sites\SitePhp;
use App\Livewire\Sites\SiteProtect;
use App\Livewire\Sites\SiteScan;
use App\Livewire\Sites\SiteShow;
use App\Livewire\Sites\SiteStatistics;
use App\Livewire\Sites\SiteWaf;
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
    Route::get('/login/dogrulama', [LoginController::class, 'challenge'])->name('login.two-factor');
    Route::post('/login/dogrulama', [LoginController::class, 'verify'])->middleware('throttle:20,1');
});

Route::middleware(['auth', EnsureActiveAccount::class, EnsureTwoFactor::class, RefuseReadOnlyChanges::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/yoneticiye-don', [ImpersonationController::class, 'destroy'])->name('impersonation.leave');
    Route::livewire('/hesap/guvenlik', AccountSecurity::class)->name('account.security');

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
        Route::livewire('/sites/{site}/dosyalar', SiteFiles::class)->whereNumber('site')->name('sites.files');
        Route::get('/sites/{site}/dosyalar/indir', [SiteFileController::class, 'download'])->whereNumber('site')->name('sites.files.download');
        Route::get('/sites/{site}/dosyalar/metin', [SiteFileController::class, 'text'])->whereNumber('site')->name('sites.files.text');
        Route::post('/sites/{site}/dosyalar/kaydet', [SiteFileController::class, 'save'])->whereNumber('site')->name('sites.files.save');
        Route::post('/sites/{site}/dosyalar/yukle', [SiteFileController::class, 'upload'])->whereNumber('site')->name('sites.files.upload');
        Route::livewire('/sites/{site}/ftp', SiteFtp::class)->whereNumber('site')->name('sites.ftp');
        Route::livewire('/sites/{site}/waf', SiteWaf::class)->whereNumber('site')->name('sites.waf');
        Route::livewire('/sites/{site}/tarama', SiteScan::class)->whereNumber('site')->name('sites.scan');
        Route::livewire('/sites/{site}/korumali-klasorler', SiteProtect::class)->whereNumber('site')->name('sites.protect');
        Route::livewire('/sites/{site}/zamanlanmis-gorevler', SiteCron::class)->whereNumber('site')->name('sites.cron');
        Route::livewire('/sites/{site}/alan-adlari', SiteDomainList::class)->whereNumber('site')->name('sites.domains');
        Route::livewire('/sites/{site}/hata-sayfalari', SiteErrorPages::class)->whereNumber('site')->name('sites.errors');
        Route::livewire('/sites/{site}/gunlukler', SiteLogs::class)->whereNumber('site')->name('sites.logs');
        Route::get('/sites/{site}/gunlukler/indir', SiteLogController::class)->whereNumber('site')->middleware('throttle:30,1')->name('sites.logs.download');
        Route::livewire('/sites/{site}/istatistikler', SiteStatistics::class)->whereNumber('site')->name('sites.stats');
        Route::livewire('/sites/{site}/disk', SiteDisk::class)->whereNumber('site')->name('sites.disk');
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
        Route::livewire('/kullanim', UsageReport::class)->name('usage');
        Route::get('/yedekler/arsiv/{item}', ServerBackupDownloadController::class)->whereNumber('item')->name('backups.download');
    });

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::livewire('/monitor', LiveMonitor::class)->name('monitor.index');
        Route::livewire('/sites/{site}/yonergeler', SiteDirectives::class)->whereNumber('site')->name('sites.directives');
        Route::livewire('/guvenlik', SecurityIndex::class)->name('security.index');
        Route::livewire('/ai', AiAssistant::class)->name('ai.index');
        Route::livewire('/ai/kayit', AiAudit::class)->name('ai.audit');
        Route::livewire('/dns/settings', DnsSettingsPage::class)->name('dns.settings');
        Route::livewire('/mail/settings', MailSettingsPage::class)->name('mail.settings');
        Route::livewire('/ssl/settings', SslSettingsPage::class)->name('ssl.settings');
        Route::livewire('/databases/settings', DatabaseSettingsPage::class)->name('databases.settings');
        Route::livewire('/yazilim', ComponentIndex::class)->name('components.index');
        Route::livewire('/hizmetler', ServiceIndex::class)->name('services.index');
        Route::livewire('/hizmetler/php/{version}', PhpVersionShow::class)->where('version', '\d\.\d')->name('services.php');
        Route::livewire('/hizmetler/{unit}', ServiceShow::class)->where('unit', '[a-z0-9][a-z0-9@._-]{0,63}')->name('services.show');
        Route::post('/yedekler/yukle/{upload}', BackupUploadController::class)->where('upload', 'up-[a-z0-9]{20}')->name('backups.upload');
        Route::livewire('/musteriler', CustomerIndex::class)->name('customers.index');
        Route::livewire('/musteriler/{customer}', CustomerShow::class)->whereNumber('customer')->name('customers.show');
        Route::livewire('/musteriler/{customer}/kullanim', UsageReport::class)->whereNumber('customer')->name('customers.usage');
        Route::post('/musteriler/{customer}/giris', [ImpersonationController::class, 'store'])->whereNumber('customer')->name('customers.impersonate');

        // Araçlar ve Ayarlar and Servik AI's settings: full administrators.
        Route::middleware(EnsureFullAdmin::class)->group(function () {
            Route::livewire('/ayarlar', GeneralSettings::class)->name('settings.general');
            Route::livewire('/ayarlar/sunucu', ServerSettings::class)->name('settings.server');
            Route::livewire('/ayarlar/bildirimler', NotificationSettings::class)->name('settings.notifications');
            Route::livewire('/ayarlar/yoneticiler', AdminIndex::class)->name('settings.admins');
            Route::livewire('/ayarlar/api', ApiKeyIndex::class)->name('settings.api');
            Route::livewire('/ayarlar/islem-gunlugu', AuditLogPage::class)->name('settings.audit');
            Route::livewire('/ayarlar/guncellemeler', UpdatesPage::class)->name('settings.updates');
            Route::get('/ayarlar/guncellemeler/ilerleme/{run}', UpdateProgressController::class)->where('run', 'upd-[a-z0-9]{20}')->name('settings.updates.progress');
            Route::livewire('/ai/settings', AiSettingsPage::class)->name('ai.settings');
        });
    });
});

// Unknown addresses go through the web middleware too, so the 404 page is
// in the reader's language.
Route::fallback(fn () => abort(404));
