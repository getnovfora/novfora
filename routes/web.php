<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\TasksController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BanController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MailWebhookController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileFieldController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WarningController;
use App\Http\Controllers\WhatsNewController;
use App\Http\Middleware\EnsureSystemPanelAccess;
use App\Http\Middleware\RequireTwoFactorForStaff;
use App\Models\Forum;
use App\Models\Post;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;

// The site root IS the community home. `/forums` is the single canonical forum URL — it is referenced
// across views and the XML sitemap — so the root permanently (301) redirects there: one canonical URL,
// no duplicate content (RH-8). Pre-install, RedirectIfNotInstalled (web group) still intercepts '/' and
// sends it to the wizard; this redirect only applies once the site is installed (or enforcement is off).
Route::get('/', fn () => redirect()->route('forums.index', [], 301));

// ── No-SSH web installer (M5, phase-1-plan §5) ─────────────────────────────────────────────────────────
// Unauthenticated pre-install surface. The `novfora.not-installed` lock 403s every installer route once
// installed (no re-trigger, no admin-reset vector); RedirectIfNotInstalled (web group) sends an
// un-installed site here. The wizard itself is the Livewire <livewire:installer.wizard /> component.
Route::middleware('novfora.not-installed')->group(function () {
    Route::view('/install', 'install.index')->name('install');
});

// Health/status endpoint for uptime monitoring (M5) — works before AND after install; no auth, no secrets.
Route::get('/health', HealthController::class)->name('health');

// ── Forums (M2) — public read, per-node authorized; anonymous resolves as the Guests group ─────────────
Route::get('/forums', [ForumController::class, 'index'])->name('forums.index');
Route::get('/forums/{forum}', [ForumController::class, 'show'])->name('forums.show');
Route::get('/topics/{topic}', [TopicController::class, 'show'])->name('topics.show');
Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

// Tags (P2-M1) — public: all tags + topics carrying a tag (filtered by forum.view).
Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
Route::get('/tags/{tag:slug}', [TagController::class, 'show'])->name('tags.show');

// Search (ADR-0010) — public; results filtered to forums the viewer can see.
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');

// SEO (system-architecture §6): XML sitemap + robots pointing at it.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', function () {
    return Response::make("User-agent: *\nAllow: /\nSitemap: ".route('sitemap')."\n", 200, ['Content-Type' => 'text/plain']);
})->name('robots');

// ── Deliverability (Spike P2) ───────────────────────────────────────────────────────────────────────
// 1-click unsubscribe (RFC 8058). Authenticated by Laravel's signed-URL HMAC (the `signed` middleware), so
// no login/CSRF token. GET-confirm / POST-apply split: a GET only renders a confirm page (resists email-
// scanner prefetch); the POST (RFC 8058 one-click or the confirm form) sets the user's cadence to 'off'.
// The POST is CSRF-exempt (bootstrap/app.php). Always registered; harmless while the digest path is dormant.
Route::match(['GET', 'POST'], '/unsubscribe/{user}', UnsubscribeController::class)
    ->middleware('signed')
    ->name('deliverability.unsubscribe');

// Provider bounce/complaint webhook — registered ONLY when configured (dormant otherwise → 404). Auth is an
// HMAC over the raw body (MailWebhookController / WebhookVerifier); CSRF-exempt (bootstrap/app.php).
if ((bool) config('novfora.deliverability.webhook.enabled') && (string) config('novfora.deliverability.webhook.secret', '') !== '') {
    Route::post('/webhooks/mail/{provider}', MailWebhookController::class)->name('deliverability.webhook');
}

// Member profiles (data-model §1) — public read.
Route::get('/users/{user}', [ProfileController::class, 'show'])->name('profiles.show');

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

    // Appearance: colour mode (auto/light/dark) + density (comfortable/compact). The form POST works with
    // no JS; the header quick-toggle posts a single field via fetch (default-theme phase, PART 2).
    Route::get('/settings/appearance', [AppearanceController::class, 'edit'])->name('settings.appearance');
    Route::post('/settings/appearance', [AppearanceController::class, 'update'])->name('settings.appearance.save');

    // In-app notifications (data-model §7): list, mark read, and per-event×channel preferences.
    Route::get('/whats-new', [WhatsNewController::class, 'index'])->name('whats-new');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('/settings/notifications', [NotificationController::class, 'preferences'])->name('settings.notifications');
    Route::post('/settings/notifications', [NotificationController::class, 'savePreferences'])->name('settings.notifications.save');

    // Profile (signature, custom fields, avatar/cover) — own account.
    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('settings.profile');
    Route::post('/settings/profile', [ProfileController::class, 'update'])->name('settings.profile.save');
});

// Admin → System panels. Requires an authenticated admin (admin.access via the permission engine);
// staff must additionally have 2FA enabled (the brief's "Must"). ADR-0003 / ADR-0006 / security §1.4.
Route::middleware(['auth', 'verified', EnsureSystemPanelAccess::class, RequireTwoFactorForStaff::class])
    ->prefix('admin/system')
    ->name('admin.system.')
    ->group(function () {
        Route::view('/service-tier', 'admin.system')->name('tier');
        Route::view('/permissions', 'admin.permissions')->name('permissions');
        Route::view('/backups', 'admin.backups')->name('backups');
        Route::view('/upgrade', 'admin.upgrade')->name('upgrade'); // no-SSH auto-upgrade status + manual apply (RH-10)

        // Email suppressions (Spike P2) — the always-available manual floor: view bounced/complained/manual
        // entries, hand-add, and un-suppress. Works on the baseline tier with no provider configured.
        Route::view('/suppressions', 'admin.suppressions')->name('suppressions');

        // Audit-log viewer (read-only, paginated, filterable) + scheduled-tasks visibility (ACP v1, PART 4).
        Route::view('/audit', 'admin.audit')->name('audit');
        Route::get('/tasks', TasksController::class)->name('tasks');

        // Admin-defined custom profile fields (data-model §1).
        Route::get('/profile-fields', [ProfileFieldController::class, 'index'])->name('profile-fields');
        Route::post('/profile-fields', [ProfileFieldController::class, 'store'])->name('profile-fields.store');
        Route::delete('/profile-fields/{field}', [ProfileFieldController::class, 'destroy'])->name('profile-fields.destroy');
    });

// ── Admin Control Panel (ACP v1) ───────────────────────────────────────────────────────────────────────
// The ACP shell: dashboard, settings pages, and the forum structure manager. Same gate as the System
// panels — an authenticated admin (admin.access via the permission engine) with 2FA (the brief's "Must").
// Every page renders inside <x-admin.shell>; Livewire SFCs additionally self-guard (their actions reach
// the component via livewire/update, which carries no route middleware). ADR-0006 / security §1.4.
Route::middleware(['auth', 'verified', EnsureSystemPanelAccess::class, RequireTwoFactorForStaff::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Forum structure manager (PART 2) — the <livewire:admin.structure /> tree.
        Route::view('/structure', 'admin.structure')->name('structure');

        // Member-group manager (ACP v2) — the <livewire:admin.groups /> manager (Admin → Members → Groups).
        Route::view('/members/groups', 'admin.groups')->name('members.groups');

        // Topic-prefix manager (P2-M1) — the <livewire:admin.prefixes /> manager.
        Route::view('/prefixes', 'admin.prefixes')->name('prefixes');

        // Settings pages (PART 3) — each a focused Livewire SFC on the Settings store.
        Route::view('/settings/general', 'admin.settings.general')->name('settings.general');
        Route::view('/settings/registration', 'admin.settings.registration')->name('settings.registration');
        Route::view('/settings/email', 'admin.settings.email')->name('settings.email');
        Route::view('/settings/moderation', 'admin.settings.moderation')->name('settings.moderation');
        Route::view('/settings/antispam', 'admin.settings.antispam')->name('settings.antispam');
        Route::view('/settings/appearance', 'admin.settings.appearance')->name('settings.appearance');
    });
