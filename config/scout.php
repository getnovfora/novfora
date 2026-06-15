<?php

// SPDX-License-Identifier: Apache-2.0

use App\Models\Post;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | Baseline tier ships with the `database` driver (MySQL/MariaDB LIKE/FULLTEXT)
    | so search works on a plain shared host with no external service. The
    | `meilisearch` driver is an OPT-IN enhanced upgrade (ADR-0010, Phase 4 · M4.1):
    | NovFora detects it via App\Services\Tier\ServiceTier (Capability::Search) and
    | degrades to the database driver automatically if it is absent or unreachable.
    |
    | Supported: "algolia", "meilisearch", "typesense", "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | Off on baseline (sync) so a cron-only host stays consistent; turn on with
    | SCOUT_QUEUE=true on the enhanced tier, where the queue is drained by a worker
    | (or by the every-minute `queue:work --stop-when-empty` baseline tick).
    |
    */

    'queue' => env('SCOUT_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | Keep soft-deleted posts OUT of the index — a deleted post must never surface
    | in search results on any tier.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Database Engine
    |--------------------------------------------------------------------------
    */

    'database' => [
        'searchable_using' => env('SCOUT_DATABASE_SEARCHABLE_STRATEGY', 'like'),
        'unsearchable_using' => env('SCOUT_DATABASE_UNSEARCHABLE_STRATEGY', 'left_match'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | The host/key may be set here via env OR overridden at runtime from the ACP
    | (Admin → Settings → Search), which pushes search.meilisearch_host /
    | search.meilisearch_key into these config keys (the key is stored encrypted).
    |
    | `index-settings` declares the attributes Meilisearch may filter/sort on.
    | forum_id is LOAD-BEARING FOR PRIVACY: the visibility filter (forum_id IN
    | [...visible]) is applied on every enhanced-tier query, so a private-club or
    | otherwise-hidden post can never be returned. Post::toSearchableArray() only
    | emits these extra attributes when the active driver is meilisearch/typesense.
    | After changing these, run `php artisan scout:sync-index-settings`.
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            Post::class => [
                'filterableAttributes' => ['forum_id', 'user_id', 'created_at'],
                'sortableAttributes' => ['created_at'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => [
                'host' => env('TYPESENSE_HOST', 'localhost'),
                'port' => env('TYPESENSE_PORT', '8108'),
                'path' => env('TYPESENSE_PATH', ''),
                'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
            ],
            'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
        'model-settings' => [
            Post::class => [
                'collection-schema' => [
                    'fields' => [
                        ['name' => 'id', 'type' => 'string'],
                        ['name' => 'body_text', 'type' => 'string'],
                        ['name' => 'forum_id', 'type' => 'int64', 'optional' => true],
                        ['name' => 'user_id', 'type' => 'int64', 'optional' => true],
                        ['name' => 'created_at', 'type' => 'int64', 'optional' => true],
                    ],
                    'default_sorting_field' => 'created_at',
                ],
                'search-parameters' => [
                    'query_by' => 'body_text',
                ],
            ],
        ],
    ],

];
