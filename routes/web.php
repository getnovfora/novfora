<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Middleware\EnsureSystemPanelAccess;
use App\Http\Middleware\RequireTwoFactorForStaff;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Authenticated, email-verified account area. 2FA setup lives here and is intentionally NOT behind
// the staff-2FA gate, so staff can reach it to comply.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/home', 'home')->name('home');
    Route::view('/settings/two-factor', 'settings.two-factor')->name('settings.two-factor');
});

// Admin → System panels. Requires an authenticated admin (admin.access via the permission engine);
// staff must additionally have 2FA enabled (the brief's "Must"). ADR-0003 / ADR-0006 / security §1.4.
Route::middleware(['auth', 'verified', EnsureSystemPanelAccess::class, RequireTwoFactorForStaff::class])
    ->prefix('admin/system')
    ->name('admin.system.')
    ->group(function () {
        Route::view('/service-tier', 'admin.system')->name('tier');
        Route::view('/permissions', 'admin.permissions')->name('permissions');
    });
