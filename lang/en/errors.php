<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 403 Forbidden
    |--------------------------------------------------------------------------
    */
    '403' => [
        'title' => 'Forbidden',
        'message' => "You don't have permission to view this. If you think that's a mistake, contact a moderator.",
    ],

    /*
    |--------------------------------------------------------------------------
    | 404 Not Found
    |--------------------------------------------------------------------------
    */
    '404' => [
        'title' => 'Page not found',
        'message' => "We couldn't find that page. It may have moved, or the link was mistyped.",
    ],

    /*
    |--------------------------------------------------------------------------
    | 419 Page Expired (CSRF token mismatch)
    |--------------------------------------------------------------------------
    */
    '419' => [
        'title' => 'Page expired',
        'message' => 'Your session timed out for security. Please go back, refresh the page, and try again.',
    ],

    /*
    |--------------------------------------------------------------------------
    | 429 Too Many Requests
    |--------------------------------------------------------------------------
    */
    '429' => [
        'title' => 'Slow down a moment',
        'message' => "You've made a lot of requests in a short time. Please wait a little while before trying again.",
    ],

    /*
    |--------------------------------------------------------------------------
    | 500 Internal Server Error
    |--------------------------------------------------------------------------
    */
    '500' => [
        'title' => 'Something went wrong',
        'message' => "An unexpected error occurred on our end. We've logged it — please try again in a moment.",
    ],

    /*
    |--------------------------------------------------------------------------
    | 503 Service Unavailable
    |--------------------------------------------------------------------------
    */
    '503' => [
        'title' => 'Be right back',
        'message' => 'The site is down for brief maintenance. Thanks for your patience — please check back shortly.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared / layout strings
    |--------------------------------------------------------------------------
    */
    'layout' => [
        'back_home' => 'Back to :app',
    ],

];
