<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Middleware\EnsureSystemPanelAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin → System → Service Tier (ADR-0003). M0: local/testing-only; M1 adds admin authorization.
Route::view('/admin/system/service-tier', 'admin.system')
    ->middleware(EnsureSystemPanelAccess::class)
    ->name('admin.system.tier');
