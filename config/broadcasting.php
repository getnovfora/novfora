<?php

// SPDX-License-Identifier: Apache-2.0

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Baseline ships with `null`/`log` — there is NO realtime daemon on a shared
    | host, so the UI updates via Livewire polling (progressive enhancement). The
    | `reverb` connection is an OPT-IN enhanced upgrade (Phase 4 · M4.2): NovFora
    | detects it via App\Services\Tier\ServiceTier (Capability::Broadcast) and
    | only then do domain events actually broadcast. Channel AUTHORIZATION
    | (routes/channels.php → App\Broadcasting\ChannelAuthorizer) runs on EVERY
    | tier, so a private-club / PM / hidden thread can never leak over a socket.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | NOTE: the `reverb` connection requires `composer require laravel/reverb`
    | (and `pusher/pusher-php-server` for the server-side client). Until then it
    | is inert — selecting it without the package installed is an operator error,
    | documented in the enable steps (PROJECT-STATE "SCAFFOLDED — NOT VALIDATED").
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options for the server-side Reverb/Pusher client.
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options.
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
