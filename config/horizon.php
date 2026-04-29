<?php

use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME', 'Malinco ERP'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 30,
    ],

    'trim' => [
        'recent'        => 60,     // joburi recente: 1 oră
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,  // failed jobs: 7 zile
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    // Joburi silențioase (nu apar în lista "completed" — prea multe)
    'silenced' => [
        \App\Jobs\FetchEmailsJob::class,
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration — Autoscaling
    |--------------------------------------------------------------------------
    | minProcesses: mereu activ minim 1 worker
    | maxProcesses: poate scala până la 6 în vârf de sarcină
    | balance: auto — Horizon decide singur câți workeri pornește
    | autoScalingStrategy: time — scalează după timpul de așteptare în coadă
    */

    'defaults' => [
        'supervisor-1' => [
            'connection'          => 'redis',
            'queue'               => ['default'],
            'balance'             => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'        => 1,
            'maxProcesses'        => 6,
            'balanceMaxShift'     => 2,
            'balanceCooldown'     => 3,
            'maxTime'             => 0,
            'maxJobs'             => 500,
            'memory'              => 256,
            'tries'               => 3,
            'timeout'             => 600,
            'nice'                => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'minProcesses'    => 1,
                'maxProcesses'    => 20,
                'balanceMaxShift' => 5,
                'balanceCooldown' => 2,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],
    ],

];
