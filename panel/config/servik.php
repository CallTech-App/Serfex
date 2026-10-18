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
    ],

];
