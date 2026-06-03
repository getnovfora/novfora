<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BanController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\WarningController;
use App\Http\Middleware\EnsureSystemPanelAccess;
use App\Http\Middleware\RequireTwoFactorForStaff;
use App\Models\Forum;
use App\Models\Post;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Forums (M2) — public read, per-node authorized; anonymous resolves as the Guests group ─────────────
Route::get('/forums', [ForumController::class, 'index'])->name('forums.index');
Route::get('/forums/{forum}', [ForumController::class, 'show'])->name('forums.show');
Route::get('/topics/{topic}', [TopicController::class, 'show'])->name('topics.show');
Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

// Compose / moderate / upload — authenticated + email-verified.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/forums/{forum}/topics/create', fn (Forum $forum) => view('forum.create-topic', ['forum' => $forum]))->name('topics.create');
    Route::get('/posts/{post}/edit', fn (Post $post) => view('forum.edit-post', ['post' => $post]))->name('posts.edit');
    Route::get('/recycle-bin', [ModerationController::class, 'recycleBin'])->name('moderation.recycle-bin');

    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/mentions', MentionController::class)->name('mentions');

    Route::post('/topics/{topic}/lock', [ModerationController::class, 'lock'])->name('topics.lock');
    Route::post('/topics/{topic}/pin', [ModerationController::class, 'pin'])->name('topics.pin');
    Route::post('/topics/{topic}/stick', [ModerationController::class, 'stick'])->name('topics.stick');
    Route::post('/topics/{topic}/move', [ModerationController::class, 'move'])->name('topics.move');
    Route::delete('/topics/{topic}', [ModerationController::class, 'destroyTopic'])->name('topics.destroy');
    Route::post('/topics/{topic}/restore', [ModerationController::class, 'restoreTopic'])->name('topics.restore');
    Route::delete('/posts/{post}', [ModerationController::class, 'destroyPost'])->name('posts.destroy');
    Route::post('/posts/{post}/restore', [ModerationController::class, 'restorePost'])->name('posts.restore');

    // Moderator control panel (MCP, security §3).
    Route::get('/moderation', [ModerationController::class, 'dashboard'])->name('moderation.dashboard');

    // Approval queue — content held by the anti-spam layer (ADR-0007 §2.4 / security §3).
    Route::get('/moderation/queue', [ModerationController::class, 'queue'])->name('moderation.queue');
    Route::post('/topics/{topic}/approve', [ModerationController::class, 'approveTopic'])->name('topics.approve');
    Route::post('/topics/{topic}/reject', [ModerationController::class, 'rejectTopic'])->name('topics.reject');
    Route::post('/posts/{post}/approve', [ModerationController::class, 'approvePost'])->name('posts.approve');
    Route::post('/posts/{post}/reject', [ModerationController::class, 'rejectPost'])->name('posts.reject');

    // Reports → staff dashboard (security §3). Any member may report; staff (bans.manage) resolve.
    Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('/moderation/reports', [ReportController::class, 'index'])->name('moderation.reports');
    Route::post('/reports/{report}/resolve', [ReportController::class, 'resolve'])->name('reports.resolve');

    // Bans + Spam Cleaner (security §3) — gated on bans.manage.
    Route::post('/bans', [BanController::class, 'store'])->name('bans.store');
    Route::delete('/bans/{ban}', [BanController::class, 'destroy'])->name('bans.destroy');
    Route::post('/users/{user}/spam-clean', [BanController::class, 'spamClean'])->name('moderation.spam-clean');

    // Warnings / infractions (security §3): staff issue (bans.manage); members acknowledge their own.
    Route::post('/users/{user}/warn', [WarningController::class, 'store'])->name('warnings.store');
    Route::get('/warnings', [WarningController::class, 'index'])->name('warnings.index');
    Route::post('/warnings/{warning}/acknowledge', [WarningController::class, 'acknowledge'])->name('warnings.acknowledge');
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
