<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\PreventRequestsDuringUpgrade;
use App\Http\Middleware\RedirectIfNotInstalled;
use Illuminate\Support\Facades\Route;

/*
| The versioned REST API (ADR-0033). Routes carry the `api` prefix automatically, so these resolve under
| /api/v1/... . Token-authenticated (AuthenticateApiToken sets the request user) and engine-authorized inside
| the controller; the `throttle:api` limiter bounds request rate (keyed by user, falling back to IP).
|
| The install + upgrade/restore maintenance gates (P5.1) run AHEAD of token auth and the limiter so the API
| never serves reads/writes against a half-migrated schema (RH-10/RH-11) or a pre-install DB — matching the
| web group. PreventRequestsDuringUpgrade emits a JSON 503 for API requests; both are O(cache-read)/file
| checks and are no-ops once installed and outside an upgrade window (and in the test opt-out).
*/

Route::prefix('v1')->middleware([
    RedirectIfNotInstalled::class,
    PreventRequestsDuringUpgrade::class,
    'throttle:api',
    AuthenticateApiToken::class,
])->group(function () {
    Route::get('/me', [V1Controller::class, 'me'])->name('api.v1.me');
    Route::get('/forums', [V1Controller::class, 'forums'])->name('api.v1.forums');
    Route::get('/forums/{forum}/topics', [V1Controller::class, 'topics'])->name('api.v1.forum.topics');
    Route::get('/topics/{topic}', [V1Controller::class, 'topic'])->name('api.v1.topic');
    Route::post('/topics/{topic}/posts', [V1Controller::class, 'createPost'])->name('api.v1.topic.posts.create');
});
