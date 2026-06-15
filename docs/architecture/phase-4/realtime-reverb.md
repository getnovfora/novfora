<!-- SPDX-License-Identifier: Apache-2.0 -->
# Real-time — Reverb broadcasting + presence (Phase 4 · M4.2, M4.3)

> ADR-0061 (broadcasting + channel authz), ADR-0062 (presence).

## How it works (for operators)

On the **baseline** tier there is no realtime daemon, so the UI updates by **polling** (the
notification bell every 30s, the PM badge every 60s, the "who's online" widget every 60s). This works
on any cron-only host.

On the **enhanced** tier, run **Laravel Reverb** and NovFora pushes updates **instantly** over
websockets — new replies, notifications, and live presence — while keeping polling as the always-on
fallback. Presence is **opt-in** per member (Settings → Appearance → "Show my online status"), **off
by default**.

## How it works (for developers)

- **Channel authorization is the security boundary (APEX).** `routes/channels.php` delegates to
  `App\Broadcasting\ChannelAuthorizer`, which resolves through the **same** permission engine
  (`forum.view`) + club gate (`Forum::clubContentVisibleTo`) the HTTP surfaces use, and the
  participant-only `ConversationPolicy` for PMs. Every check **fails closed** and runs on every tier
  (`withBroadcasting` in `bootstrap/app.php`) — a private-club / PM / hidden thread can never leak
  over a socket, even with a null/log broadcaster.
- Channels: `notifications.{userId}` (owner only), `thread.{topicId}`, `conversation.{conversationId}`
  (private), `online` + `club-presence.{clubId}` (presence — opted-in members; club channel is
  active-members only, so a non-member can't enumerate a private club's online roster).
- Events `PostCreated`, `MessageSent`, `NotificationReceived` implement `ShouldBroadcast` with
  `broadcastWhen()` gated on `ServiceTier::isEnhanced(Capability::Broadcast)` and **id-only payloads**
  (no bodies/PII — the client refetches). Baseline pays nothing.
- `App\Presence\OnlineMembers` is the single source of truth for the opt-in + active + recent-window
  rule; the theme widget and the live `⚡online-members` widget both read it.

## ⚠ SCAFFOLDED — NOT VALIDATED against a real Reverb

The **channel-authorization logic** is fully proven server-side (tested on the null driver), but the
websocket round-trip is not — no Reverb/Pusher server or client (Echo) is in the build. The thread-page
live-append needs Echo bundled (this repo ships prebuilt assets / no Node). **To validate / enable:**

1. `composer require laravel/reverb pusher/pusher-php-server`
2. `php artisan reverb:install`
3. Set `BROADCAST_CONNECTION=reverb` + `REVERB_APP_ID/KEY/SECRET` + `REVERB_HOST/PORT/SCHEME`.
4. `npm install laravel-echo pusher-js`, configure `window.Echo` (reverb), `npm run build`.
5. Run `php artisan reverb:start` under a supervisor (systemd/supervisord).
6. Verify a non-member is rejected by `/broadcasting/auth` for a private-club thread channel.
