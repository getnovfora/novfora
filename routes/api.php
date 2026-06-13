<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\Api\V1Controller;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

/*
| The versioned REST API (ADR-0033). Routes carry the `api` prefix automatically, so these resolve under
| /api/v1/... . Token-authenticated (AuthenticateApiToken sets the request user) and engine-authorized inside
| the controller; the `throttle:api` limiter bounds request rate (keyed by user, falling back to IP).
*/

Route::prefix('v1')->middleware(['throttle:api', AuthenticateApiToken::class])->group(function () {
    Route::get('/me', [V1Controller::class, 'me'])->name('api.v1.me');
    Route::get('/forums', [V1Controller::class, 'forums'])->name('api.v1.forums');
    Route::get('/forums/{forum}/topics', [V1Controller::class, 'topics'])->name('api.v1.forum.topics');
    Route::get('/topics/{topic}', [V1Controller::class, 'topic'])->name('api.v1.topic');
    Route::post('/topics/{topic}/posts', [V1Controller::class, 'createPost'])->name('api.v1.topic.posts.create');
});
