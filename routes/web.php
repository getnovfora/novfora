<?php

// SPDX-License-Identifier: Apache-2.0

use App\Community\MembersDirectory;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\TasksController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BanController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MailWebhookController;
use App\Http\Controllers\MentionController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileFieldController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavedSearchController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TrendingController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WarningController;
use App\Http\Controllers\WhatsNewController;
use App\Http\Middleware\EnsureSystemPanelAccess;
use App\Http\Middleware\RequireTwoFactorForStaff;
use App\Models\Conversation;
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

// Trending / best-of (discovery 3.1) — public, permission-safe.
Route::get('/trending', [TrendingController::class, 'index'])->name('trending.index');

// ── Clubs (Phase 4 · M1.1) — sub-communities. The directory is public; per-club listing visibility is
// enforced in the controller (an unlisted club a viewer may not see 404s — no disclosure). The literal
// "create" segment is registered (auth+verified) BEFORE the {club} wildcard so it is never read as a slug.
Route::get('/clubs', [ClubController::class, 'index'])->name('clubs.index');
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/clubs/create', [ClubController::class, 'create'])->name('clubs.create');
    Route::get('/clubs/{club:slug}/edit', [ClubController::class, 'edit'])->name('clubs.edit');
});
Route::get('/clubs/{club:slug}', [ClubController::class, 'show'])->name('clubs.show');

// RSS/Atom feeds (discovery 3.2) — public; each exposes only guest-visible content (private forums 404).
Route::get('/forums/{forum}/feed', [FeedController::class, 'forum'])->name('feeds.forum');
Route::get('/topics/{topic}/feed', [FeedController::class, 'topic'])->name('feeds.topic');
Route::get('/users/{user}/feed', [FeedController::class, 'user'])->name('feeds.user');
// withTrashed: a merged topic is soft-deleted but its URL must still resolve so show() can 301 it to the
// merge target (P2-M4). An ordinary soft-deleted topic is re-checked and 404s inside the controller.
Route::get('/topics/{topic}', [TopicController::class, 'show'])->name('topics.show')->withTrashed();
Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show');

// Tags (P2-M1) — public: all tags + topics carrying a tag (filtered by forum.view).
Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
Route::get('/tags/{tag:slug}', [TagController::class, 'show'])->name('tags.show');

// Search (ADR-0010) — public; results filtered to forums the viewer can see.
// Throttled (Wave 8.4 hardening): search is public and unauthenticated; the rate cap is defence-in-depth
// against request-flood abuse on top of the operator parser's bounded resolution.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');
});

// Language switcher (Wave 8.1) — open to guests and members; the controller validates the locale against
// the allowlist before it touches the session/profile. Throttled as a cheap write endpoint.
Route::post('/locale', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')->name('locale.update');

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

// Members directory (public listing) — visibility is admin-controlled (Admin → Members → Directory). The
// gate and the directory component share App\Community\MembersDirectory::visibleTo(); a non-visible viewer
// gets a 404 (no disclosure). The route stays registered so the nav can Route::has() it.
Route::get('/members', function () {
    abort_unless(MembersDirectory::visibleTo(auth()->user()), 404);

    return view('members.index');
})->name('members.index');

// "Top members" leaderboard (A2) — same visibility gate as the directory (404 for a non-visible viewer); the
// <livewire:leaderboard> component re-asserts it. A separate path avoids a /members/{user} wildcard.
Route::get('/members/top', function () {
    abort_unless(MembersDirectory::visibleTo(auth()->user()), 404);

    return view('members.top');
})->name('members.top');

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

    // Admin-forced account deletion (ADR-0025) — same gate as spam-clean (bans.manage + rank) plus the
    // deletion-specific guards in AccountDeletionService. GET shows the summary + confirm; DELETE executes.
    Route::get('/users/{user}/delete', [BanController::class, 'confirmDelete'])->name('moderation.user-delete.confirm');
    Route::delete('/users/{user}', [BanController::class, 'forceDelete'])->name('moderation.user-delete');

    // Warnings / infractions (security §3): staff issue (bans.manage); members acknowledge their own.
    Route::post('/users/{user}/warn', [WarningController::class, 'store'])->name('warnings.store');
    Route::get('/warnings', [WarningController::class, 'index'])->name('warnings.index');
    Route::post('/warnings/{warning}/acknowledge', [WarningController::class, 'acknowledge'])->name('warnings.acknowledge');
});

// Authenticated, email-verified account area. 2FA setup lives here and is intentionally NOT behind
// the staff-2FA gate, so staff can reach it to comply.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/home', 'home')->name('home');

    // Saved topics + posts (member tool 2.1).
    Route::get('/saved', [BookmarkController::class, 'index'])->name('saved.index');

    // Scheduled replies (member tool 2.4).
    Route::view('/scheduled', 'scheduled.index')->name('scheduled.index');

    // Saved searches (search 6.1).
    Route::get('/saved-searches', [SavedSearchController::class, 'index'])->name('saved-searches.index');
    Route::post('/saved-searches', [SavedSearchController::class, 'store'])->name('saved-searches.store');
    Route::delete('/saved-searches/{search}', [SavedSearchController::class, 'destroy'])->name('saved-searches.destroy');

    Route::view('/settings/two-factor', 'settings.two-factor')->name('settings.two-factor');

    // Consolidated display preferences (P2-M4): posts-per-page + thread sort order. The ⚡user-preferences SFC
    // reads/writes the authenticated user only.
    Route::view('/settings/preferences', 'settings.preferences')->name('settings.preferences');

    // Ignored members (member tool 2.2).
    Route::view('/settings/ignore-list', 'settings.ignore-list')->name('settings.ignore-list');

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

    // Account: voluntary deletion (ADR-0025). The ⚡delete-account SFC re-authenticates + confirms; the
    // cascade and all guards live in AccountDeletionService.
    Route::view('/settings/account', 'settings.account')->name('settings.account');

    // Personal API tokens (ADR-0033, B3) — the ⚡api-tokens SFC issues/revokes the user's own tokens.
    Route::view('/settings/api-tokens', 'settings.api-tokens')->name('settings.api-tokens');

    // Private messages (P2-M2 Half-B). The /messages/new route MUST be registered before {conversation}
    // so the literal "new" segment is never captured as a conversation id.
    Route::get('/messages', fn () => view('pm.inbox'))->name('pm.inbox');
    Route::get('/messages/new', fn () => view('pm.new'))->name('pm.create');
    Route::get('/messages/{conversation}', fn (Conversation $conversation) => view('pm.conversation', ['conversation' => $conversation]))->name('pm.show');
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

        // Badge manager (P2-M5 Slice 3) — the <livewire:admin.badges /> manager.
        Route::view('/badges', 'admin.badges')->name('badges');

        // Module / plugin manager (ADR-0031, B1) — the <livewire:admin.modules /> lifecycle surface.
        Route::view('/modules', 'admin.modules')->name('modules');

        // Layout / widget configurator (ADR-0032, B2) — the <livewire:admin.layout /> region editor.
        Route::view('/layout', 'admin.layout')->name('layout');

        // Outbound webhooks (ADR-0033, B3) — the <livewire:admin.webhooks /> endpoint manager.
        Route::view('/webhooks', 'admin.webhooks')->name('webhooks');

        // Admin analytics (ADR-0035, B5) — the <livewire:admin.analytics /> aggregate dashboard.
        Route::view('/analytics', 'admin.analytics')->name('analytics');

        // Settings pages (PART 3) — each a focused Livewire SFC on the Settings store.
        Route::view('/settings/general', 'admin.settings.general')->name('settings.general');
        Route::view('/settings/registration', 'admin.settings.registration')->name('settings.registration');
        Route::view('/settings/email', 'admin.settings.email')->name('settings.email');
        Route::view('/settings/moderation', 'admin.settings.moderation')->name('settings.moderation');
        Route::view('/settings/antispam', 'admin.settings.antispam')->name('settings.antispam');
        Route::view('/settings/appearance', 'admin.settings.appearance')->name('settings.appearance');
        Route::view('/settings/themes', 'admin.settings.themes')->name('settings.themes'); // visual theme editor
        Route::view('/settings/templates', 'admin.settings.templates')->name('settings.templates'); // sandboxed template editor (ADR-0038)

        // Members directory visibility (the public /members listing is gated on this setting).
        Route::view('/members/directory', 'admin.members.directory')->name('members.directory');
    });

// Importer 301 redirect maps (ADR-0034) — the LAST route: only consulted for an otherwise-unmatched URL (a
// legacy link), so the redirects table is never touched on the hot path.
Route::fallback(LegacyRedirectController::class);
