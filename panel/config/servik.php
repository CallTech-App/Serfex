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

];
