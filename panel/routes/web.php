<?php

use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Backups\BackupDownloadController as ServerBackupDownloadController;
use App\Http\Controllers\Backups\BackupUploadController;
use App\Http\Controllers\CliDownloadController;
use App\Http\Controllers\Customers\ImpersonationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Databases\DatabaseManagerController;
use App\Http\Controllers\Git\GitConnectController;
use App\Http\Controllers\Laravel\BackupDownloadController;
use App\Http\Controllers\Laravel\DeployWebhookController;
use App\Http\Controllers\Mail\WebmailController;
use App\Http\Controllers\Settings\UpdateProgressController;
use App\Http\Controllers\Sites\SiteFileController;
use App\Http\Controllers\Sites\SiteLogController;
use App\Http\Controllers\Sites\WordPressLoginController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureFullAdmin;
use App\Http\Middleware\EnsureHostingAccess;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RefuseReadOnlyChanges;
use App\Livewire\Account\AccountGit;
use App\Livewire\Account\AccountProfile;
use App\Livewire\Account\AccountSecurity;
use App\Livewire\Account\CliApprove;
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
use App\Livewire\Settings\AppTemplateIndex;
use App\Livewire\Settings\AppTemplateShow;
use App\Livewire\Settings\ApiKeyIndex;
use App\Livewire\Settings\AuditLogPage;
use App\Livewire\Settings\GeneralSettings;
use App\Livewire\Settings\GitProviderSettings;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\ServerSettings;
use App\Livewire\Settings\UpdatesPage;
use App\Livewire\Setup\SetupWizard;
use App\Livewire\Sites\SiteCaching;
use App\Livewire\Sites\SiteConnection;
use App\Livewire\Sites\SiteCopyTool;
use App\Livewire\Sites\SiteCron;
use App\Livewire\Sites\SiteDirectives;
use App\Livewire\Sites\SiteDisk;
use App\Livewire\Sites\SiteDomainList;
use App\Livewire\Sites\SiteErrorPages;
use App\Livewire\Sites\SiteFiles;
use App\Livewire\Sites\SiteFtp;
use App\Livewire\Sites\SiteGit;
use App\Livewire\Sites\SiteIndex;
use App\Livewire\Sites\SiteLogs;
use App\Livewire\Sites\SiteMigrate;
use App\Livewire\Sites\SitePackages;
use App\Livewire\Sites\SitePhp;
use App\Livewire\Sites\SiteProtect;
use App\Livewire\Sites\SiteScan;
use App\Livewire\Sites\SiteShow;
use App\Livewire\Sites\SiteSsh;
use App\Livewire\Sites\SiteStatistics;
use App\Livewire\Sites\SiteWaf;
use App\Livewire\Sites\SiteWordPress;
use App\Livewire\Ssl\SiteSsl;
use App\Livewire\Ssl\SslIndex;
use App\Livewire\Ssl\SslSettingsPage;
use Illuminate\Support\Facades\Route;

Route::post('/hooks/deploy/{token}', DeployWebhookController::class)
    ->where('token', '[A-Za-z0-9]+')->middleware('throttle:30,1')->name('laravel.webhook');

Route::get('/invite/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{40,80}')->middleware('throttle:30,1')->name('invitation');
Route::post('/invite/{token}', [InvitationController::class, 'store'])->where('token', '[A-Za-z0-9]{40,80}')->middleware('throttle:10,1');

// The link mailed to a new sign-in address; it works signed in or not.
Route::get('/account/email/confirm/{token}', [EmailChangeController::class, 'show'])->where('token', '[A-Za-z0-9]{40,100}')->middleware('throttle:30,1')->name('account.email.confirm');
Route::post('/account/email/confirm/{token}', [EmailChangeController::class, 'store'])->where('token', '[A-Za-z0-9]{40,100}')->middleware('throttle:10,1');

// The serfex command line, for developers' own computers: the install
// script and the binaries of this server's release.
Route::get('/cli/install.sh', [CliDownloadController::class, 'script'])->middleware('throttle:30,1')->name('cli.install');
Route::get('/cli/download/{file}', [CliDownloadController::class, 'download'])->where('file', 'SHA256SUMS|serfex-cli-(linux|darwin)-(amd64|arm64)')->middleware('throttle:30,1')->name('cli.download');

// The one-time setup link install.sh prints: it creates the first
// administrator. Wrong tokens are throttled like failed logins.
Route::get('/install/{token}', [SetupController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('setup.link');
Route::post('/install/{token}', [SetupController::class, 'store'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/login/two-factor', [LoginController::class, 'challenge'])->name('login.two-factor');
    Route::post('/login/two-factor', [LoginController::class, 'verify'])->middleware('throttle:20,1');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->where('token', '[A-Za-z0-9]{40,100}')->middleware('throttle:30,1')->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

Route::middleware(['auth', EnsureActiveAccount::class, EnsureTwoFactor::class, RefuseReadOnlyChanges::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/impersonation/leave', [ImpersonationController::class, 'destroy'])->name('impersonation.leave');
    Route::livewire('/account', AccountProfile::class)->name('account.profile');
    Route::livewire('/account/security', AccountSecurity::class)->name('account.security');
    Route::livewire('/account/git', AccountGit::class)->name('account.git');
    // Connecting a GitHub, GitLab or Bitbucket account (OAuth).
    Route::get('/git/connect/{provider}', [GitConnectController::class, 'redirect'])->whereIn('provider', ['github', 'gitlab', 'bitbucket'])->middleware('throttle:20,1')->name('git.connect');
    Route::get('/git/callback/{provider}', [GitConnectController::class, 'callback'])->whereIn('provider', ['github', 'gitlab', 'bitbucket'])->middleware('throttle:20,1')->name('git.callback');
    Route::livewire('/cli/approve', CliApprove::class)->name('cli.approve');

    // Laravel pages are also for members of applications; each page
    // checks the account's role on the application.
    Route::livewire('/laravel', LaravelIndex::class)->name('laravel.index');
    Route::livewire('/laravel/{app}', LaravelShow::class)->whereNumber('app')->name('laravel.show');
    Route::get('/laravel/{app}/backups/{backup}', BackupDownloadController::class)->whereNumber(['app', 'backup'])->name('laravel.backup.download');

    // Hosting pages: administrators see everything, customers their own.
    Route::middleware(EnsureHostingAccess::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::livewire('/sites', SiteIndex::class)->name('sites.index');
        Route::livewire('/sites/{site}', SiteShow::class)->whereNumber('site')->name('sites.show');
        Route::livewire('/sites/{site}/ssl', SiteSsl::class)->whereNumber('site')->name('sites.ssl');
        Route::livewire('/sites/{site}/php', SitePhp::class)->whereNumber('site')->name('sites.php');
        Route::livewire('/sites/{site}/files', SiteFiles::class)->whereNumber('site')->name('sites.files');
        Route::get('/sites/{site}/files/download', [SiteFileController::class, 'download'])->whereNumber('site')->name('sites.files.download');
        Route::get('/sites/{site}/files/text', [SiteFileController::class, 'text'])->whereNumber('site')->name('sites.files.text');
        Route::post('/sites/{site}/files/save', [SiteFileController::class, 'save'])->whereNumber('site')->name('sites.files.save');
        Route::post('/sites/{site}/files/upload', [SiteFileController::class, 'upload'])->whereNumber('site')->name('sites.files.upload');
        Route::livewire('/sites/{site}/ftp', SiteFtp::class)->whereNumber('site')->name('sites.ftp');
        Route::livewire('/sites/{site}/ssh', SiteSsh::class)->whereNumber('site')->name('sites.ssh');
        Route::livewire('/sites/{site}/connection-details', SiteConnection::class)->whereNumber('site')->name('sites.connection');
        Route::livewire('/sites/{site}/copy', SiteCopyTool::class)->whereNumber('site')->name('sites.copy');
        Route::livewire('/sites/{site}/cache', SiteCaching::class)->whereNumber('site')->name('sites.cache');
        Route::livewire('/sites/{site}/packages', SitePackages::class)->whereNumber('site')->name('sites.packages');
        Route::livewire('/sites/{site}/waf', SiteWaf::class)->whereNumber('site')->name('sites.waf');
        Route::livewire('/sites/{site}/scan', SiteScan::class)->whereNumber('site')->name('sites.scan');
        Route::livewire('/sites/{site}/protected-folders', SiteProtect::class)->whereNumber('site')->name('sites.protect');
        Route::livewire('/sites/{site}/cron-jobs', SiteCron::class)->whereNumber('site')->name('sites.cron');
        Route::livewire('/sites/{site}/git', SiteGit::class)->whereNumber('site')->name('sites.git');
        Route::livewire('/sites/{site}/wordpress', SiteWordPress::class)->whereNumber('site')->name('sites.wordpress');
        Route::post('/sites/{site}/wordpress/login', WordPressLoginController::class)->whereNumber('site')->middleware('throttle:20,1')->name('sites.wordpress.login');
        Route::livewire('/sites/{site}/domains', SiteDomainList::class)->whereNumber('site')->name('sites.domains');
        Route::livewire('/sites/{site}/error-pages', SiteErrorPages::class)->whereNumber('site')->name('sites.errors');
        Route::livewire('/sites/{site}/logs', SiteLogs::class)->whereNumber('site')->name('sites.logs');
        Route::get('/sites/{site}/logs/download', SiteLogController::class)->whereNumber('site')->middleware('throttle:30,1')->name('sites.logs.download');
        Route::livewire('/sites/{site}/statistics', SiteStatistics::class)->whereNumber('site')->name('sites.stats');
        Route::livewire('/sites/{site}/disk', SiteDisk::class)->whereNumber('site')->name('sites.disk');
        Route::livewire('/dns', ZoneIndex::class)->name('dns.index');
        Route::livewire('/dns/{zone}', ZoneShow::class)->whereNumber('zone')->name('dns.show');
        Route::livewire('/mail', MailIndex::class)->name('mail.index');
        Route::livewire('/mail/{domain}', MailDomainShow::class)->whereNumber('domain')->name('mail.show');
        Route::get('/mail/mailboxes/{mailbox}/webmail', WebmailController::class)->whereNumber('mailbox')->name('mail.webmail');
        Route::livewire('/ssl', SslIndex::class)->name('ssl.index');
        Route::livewire('/databases', DatabaseIndex::class)->name('databases.index');
        Route::livewire('/databases/{database}', DatabaseShow::class)->whereNumber('database')->name('databases.show');
        Route::get('/databases/{database}/manager', DatabaseManagerController::class)->whereNumber('database')->middleware('throttle:30,1')->name('databases.manager');
        Route::livewire('/backups', BackupIndex::class)->name('backups.index');
        Route::livewire('/usage', UsageReport::class)->name('usage');
        Route::get('/backups/archive/{item}', ServerBackupDownloadController::class)->whereNumber('item')->name('backups.download');
    });

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::livewire('/monitor', LiveMonitor::class)->name('monitor.index');
        Route::livewire('/sites/{site}/directives', SiteDirectives::class)->whereNumber('site')->name('sites.directives');
        Route::livewire('/sites/migrate', SiteMigrate::class)->name('sites.migrate');
        Route::livewire('/security', SecurityIndex::class)->name('security.index');
        Route::livewire('/ai', AiAssistant::class)->name('ai.index');
        Route::livewire('/ai/audit', AiAudit::class)->name('ai.audit');
        Route::livewire('/dns/settings', DnsSettingsPage::class)->name('dns.settings');
        Route::livewire('/mail/settings', MailSettingsPage::class)->name('mail.settings');
        Route::livewire('/ssl/settings', SslSettingsPage::class)->name('ssl.settings');
        Route::livewire('/databases/settings', DatabaseSettingsPage::class)->name('databases.settings');
        Route::livewire('/software', ComponentIndex::class)->name('components.index');
        Route::livewire('/services', ServiceIndex::class)->name('services.index');
        Route::livewire('/services/php/{version}', PhpVersionShow::class)->where('version', '\d\.\d')->name('services.php');
        Route::livewire('/services/{unit}', ServiceShow::class)->where('unit', '[a-z0-9][a-z0-9@._-]{0,63}')->name('services.show');
        Route::post('/backups/upload/{upload}', BackupUploadController::class)->where('upload', 'up-[a-z0-9]{20}')->name('backups.upload');
        Route::livewire('/customers', CustomerIndex::class)->name('customers.index');
        Route::livewire('/customers/{customer}', CustomerShow::class)->whereNumber('customer')->name('customers.show');
        Route::livewire('/customers/{customer}/usage', UsageReport::class)->whereNumber('customer')->name('customers.usage');
        Route::post('/customers/{customer}/impersonate', [ImpersonationController::class, 'store'])->whereNumber('customer')->name('customers.impersonate');

        // Araçlar ve Ayarlar and Serfex AI's settings: full administrators.
        Route::middleware(EnsureFullAdmin::class)->group(function () {
            Route::livewire('/install', SetupWizard::class)->name('setup');
            Route::livewire('/settings', GeneralSettings::class)->name('settings.general');
            Route::livewire('/settings/server', ServerSettings::class)->name('settings.server');
            Route::livewire('/settings/notifications', NotificationSettings::class)->name('settings.notifications');
            Route::livewire('/settings/admins', AdminIndex::class)->name('settings.admins');
            Route::livewire('/settings/api', ApiKeyIndex::class)->name('settings.api');
            Route::livewire('/settings/git-providers', GitProviderSettings::class)->name('settings.git');
            Route::livewire('/settings/app-templates', AppTemplateIndex::class)->name('settings.templates');
            Route::livewire('/settings/app-templates/{template}', AppTemplateShow::class)->whereNumber('template')->name('settings.templates.show');
            Route::livewire('/settings/audit-log', AuditLogPage::class)->name('settings.audit');
            Route::livewire('/settings/updates', UpdatesPage::class)->name('settings.updates');
            Route::get('/settings/updates/progress/{run}', UpdateProgressController::class)->where('run', 'upd-[a-z0-9]{20}')->name('settings.updates.progress');
            Route::livewire('/ai/settings', AiSettingsPage::class)->name('ai.settings');
        });
    });
});

// Unknown addresses go through the web middleware too, so the 404 page is
// in the reader's language.
Route::fallback(fn () => abort(404));
