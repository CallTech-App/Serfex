<?php

return [

    // The release's VERSION file (written by scripts/build-release.sh);
    // a checkout from git is "dev".
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : env('SERFEX_VERSION', 'dev'),

    // The time zone the panel shows times in and runs its schedules by.
    // Araçlar ve Ayarlar → Genel sets it together with the server's own;
    // dates are stored in UTC either way.
    'timezone' => env('SERFEX_TIMEZONE', 'Europe/Istanbul'),

    // The panel's languages, each in its own name. Turkish is the source:
    // views and code are written in it, lang/en.json translates. The
    // server's default (Araçlar ve Ayarlar → Genel) starts as SERFEX_LOCALE:
    // English, or Turkish when install.sh ran with --lang tr. The setup
    // page's language choice replaces it.
    'locales' => ['tr' => 'Türkçe', 'en' => 'English'],
    'locale' => env('SERFEX_LOCALE', 'en'),

    // The serfex command line's binaries for developers' computers
    // (linux and darwin, amd64 and arm64), with SHA256SUMS and
    // install-cli.sh: the release's downloads/ folder. The panel serves
    // them at /cli/install.sh and /cli/download/<file>.
    'cli_downloads' => env('SERFEX_CLI_DOWNLOADS', dirname(base_path()).'/downloads'),

    // File systems watched for free space (Bildirimler).
    'disk_paths' => ['/', '/home', '/var'],

    /*
    |--------------------------------------------------------------------------
    | Serfex Agent
    |--------------------------------------------------------------------------
    |
    | The panel never runs as root. Privileged server operations are sent to
    | serfex-agent over a local Unix socket using JSON-RPC 2.0.
    |
    */

    'agent' => [
        'socket' => env('SERFEX_AGENT_SOCKET', '/run/serfex-agent/agent.sock'),
        'timeout' => (float) env('SERFEX_AGENT_TIMEOUT', 5),
        // Seconds to wait for changes that validate and reload services.
        'job_timeout' => (float) env('SERFEX_AGENT_JOB_TIMEOUT', 120),
        // Seconds to wait for a certificate, validation included.
        'ssl_timeout' => (float) env('SERFEX_AGENT_SSL_TIMEOUT', 300),
        // Seconds to wait for a Laravel deploy or a command run from the
        // panel; the agent stops a command after 30 minutes.
        'deploy_timeout' => (float) env('SERFEX_AGENT_DEPLOY_TIMEOUT', 1900),
        // Seconds to wait for an application backup or restore; the agent
        // stops one after two hours.
        'backup_timeout' => (float) env('SERFEX_AGENT_BACKUP_TIMEOUT', 7300),
        // Seconds to wait for a whole-server backup or a site restore from
        // Yedekler; the agent stops one after twelve hours.
        'server_backup_timeout' => (float) env('SERFEX_AGENT_SERVER_BACKUP_TIMEOUT', 43300),
        // Seconds to wait for software installed from Yazılım Yönetimi;
        // ClamAV's first signature download alone can take minutes.
        'component_timeout' => (float) env('SERFEX_AGENT_COMPONENT_TIMEOUT', 1750),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSL certificates
    |--------------------------------------------------------------------------
    |
    | Before asking Let's Encrypt, the panel checks that each name resolves
    | to this server, so a domain whose DNS is elsewhere waits instead of
    | using up Let's Encrypt's failed-validation limit. Turn the check off
    | for local development with a dry-run agent.
    |
    */

    'ssl' => [
        'check_dns' => (bool) env('SERFEX_SSL_CHECK_DNS', true),
        // Let's Encrypt certificates are renewed this many days before expiry.
        'renew_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | E-mail
    |--------------------------------------------------------------------------
    |
    | Mail domain pages look the domain's records up in public DNS to show
    | whether MX, SPF, DKIM and DMARC have propagated. Turn the lookups off
    | for local development and tests.
    |
    */

    'mail' => [
        'check_dns' => (bool) env('SERFEX_MAIL_CHECK_DNS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serfex AI
    |--------------------------------------------------------------------------
    |
    | The assistant talks to the Claude API with the key saved on the
    | Serfex AI settings page. base_url points it at another endpoint, for
    | example a proxy; leave it empty for api.anthropic.com.
    |
    */

    'ai' => [
        'base_url' => env('SERFEX_AI_BASE_URL') ?: null,
        // Requests to Claude in one answer, tool calls included.
        'max_steps' => 16,
        'max_tokens' => 16000,
        // Seconds to wait for one response from Claude.
        'timeout' => (float) env('SERFEX_AI_TIMEOUT', 300),
    ],

];
