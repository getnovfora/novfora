<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\EmbedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Embed surface (U7, ADR-0103) — /embed/v1/*
|--------------------------------------------------------------------------
| Registered OUTSIDE the `web` group (bootstrap/app.php `then:`) so these endpoints are stateless:
| no session, no cookies, no CSRF surface, GET-only. The group carries install/upgrade/board-online
| gating + the `embed` rate limiter; each response sets its own security headers in EmbedController.
*/

Route::get('/w/{widget}', [EmbedController::class, 'widget'])
    ->where('widget', '[a-z-]{1,32}')
    ->name('widget');

Route::get('/d/{widget}.json', [EmbedController::class, 'data'])
    ->where('widget', '[a-z-]{1,32}')
    ->name('data');
