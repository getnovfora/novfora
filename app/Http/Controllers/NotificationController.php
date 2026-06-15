<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Forum;
use App\Models\NotificationPreference;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notifications (data-model §7) — list + mark-read, and per-event×channel preferences. The unread
 * count is polled by a Livewire island (baseline real-time; Reverb is Phase 4).
 */
class NotificationController extends Controller
{
    // reaction has a LIVE emitter (P2-M1 Reacted → SendReactionNotification). pm.received (M2 Half-B) and
    // follow (M3) are seated now so they slot in without a later migration; their emitters land in those
    // milestones (no fake emitters here).
    public const EVENTS = ['reply', 'mention', 'reaction', 'pm.received', 'follow', 'moderation'];

    public const CHANNELS = ['database', 'mail'];

    public function index(Request $request): View
    {
        $user = $this->user($request);

        // M1.5: a stored reply/mention/reaction notification snapshots the club topic title; re-gate it
        // against the recipient's CURRENT club access at render time (the recipient may have left the club, or
        // it may have gone private), exactly as BookmarkController re-checks a saved reference.
        $page = $user->notifications()->latest()->paginate(30);
        $page->setCollection(
            $page->getCollection()->filter(fn (DatabaseNotification $n): bool => $this->stillVisible($n, $user))->values()
        );

        return view('notifications.index', ['notifications' => $page]);
    }

    /** Whether a stored notification's referenced club topic is still visible to the recipient. */
    private function stillVisible(DatabaseNotification $notification, User $user): bool
    {
        if (! in_array($notification->type, ['reply', 'mention', 'reaction'], true)) {
            return true; // non-topic notifications carry no club content
        }

        $threadId = $notification->data['thread_id'] ?? null;
        if (! is_numeric($threadId)) {
            return true;
        }

        $topic = Topic::find((int) $threadId);
        if (! $topic instanceof Topic) {
            return true; // a deleted topic has no club content left to leak
        }

        $forum = $topic->forum;

        return ! $forum instanceof Forum || $forum->clubContentVisibleTo($user);
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $this->user($request)->notifications()->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function preferences(Request $request): View
    {
        $current = NotificationPreference::where('user_id', $this->user($request)->getKey())->get()
            ->mapWithKeys(fn (NotificationPreference $p) => ["{$p->event_type}.{$p->channel}" => (bool) $p->enabled]);

        return view('settings.notifications', [
            'current' => $current,
            'events' => self::EVENTS,
            'channels' => self::CHANNELS,
        ]);
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        foreach (self::EVENTS as $event) {
            foreach (self::CHANNELS as $channel) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $user->getKey(), 'event_type' => $event, 'channel' => $channel],
                    ['enabled' => $request->boolean("pref.{$event}.{$channel}")],
                );
            }
        }

        return back()->with('status', 'Notification preferences saved.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
