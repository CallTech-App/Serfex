<?php

return [

    'version' => env('SERVIK_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Servik Agent
    |--------------------------------------------------------------------------
    |
    | The panel never runs as root. Privileged server operations are sent to
    | servik-agent over a local Unix socket using JSON-RPC 2.0.
    |
    */

    'agent' => [
        'socket' => env('SERVIK_AGENT_SOCKET', '/run/servik-agent/agent.sock'),
        'timeout' => (float) env('SERVIK_AGENT_TIMEOUT', 5),
        // Seconds to wait for changes that validate and reload services.
        'job_timeout' => (float) env('SERVIK_AGENT_JOB_TIMEOUT', 120),
        // Seconds to wait for a certificate, validation included.
        'ssl_timeout' => (float) env('SERVIK_AGENT_SSL_TIMEOUT', 300),
        // Seconds to wait for a Laravel deploy or a command run from the
        // panel; the agent stops a command after 30 minutes.
        'deploy_timeout' => (float) env('SERVIK_AGENT_DEPLOY_TIMEOUT', 1900),
        // Seconds to wait for an application backup or restore; the agent
        // stops one after two hours.
        'backup_timeout' => (float) env('SERVIK_AGENT_BACKUP_TIMEOUT', 7300),
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
        'check_dns' => (bool) env('SERVIK_SSL_CHECK_DNS', true),
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
        'check_dns' => (bool) env('SERVIK_MAIL_CHECK_DNS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Servik AI
    |--------------------------------------------------------------------------
    |
    | The assistant talks to the Claude API with the key saved on the
    | Servik AI settings page. base_url points it at another endpoint, for
    | example a proxy; leave it empty for api.anthropic.com.
    |
    */

    'ai' => [
        'base_url' => env('SERVIK_AI_BASE_URL') ?: null,
        // Requests to Claude in one answer, tool calls included.
        'max_steps' => 16,
        'max_tokens' => 16000,
        // Seconds to wait for one response from Claude.
        'timeout' => (float) env('SERVIK_AI_TIMEOUT', 300),
    ],

];
