<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Phase 4 · M3 — PWA + Web Push

> Design record. ADRs: **0057** (PWA), **0058** (Web Push channel), **0059** (push preferences UI). Progressive
> enhancement throughout — nothing on the Baseline tier depends on either.

## Installable PWA (ADR-0057)

A web app **manifest** (`/manifest.webmanifest`: name, `start_url`/`scope` `/`, `display: standalone`, theme/
background colours, a scalable maskable SVG icon) + a root-scoped **service worker** (`/sw.js`) wired into the
layout head, with an `/offline` fallback page. A browser without SW support simply ignores it.

### The "never authed mutations / PII" fence — two enforcements

1. The service worker **only handles GET**. Every mutation (POST/PUT/PATCH/DELETE) passes straight to the
   network — never cached, never replayed.
2. Page HTML is cached **only when the server flags it safe**: the `PwaResponseHeaders` middleware sets
   `X-PWA-Cacheable: 1` solely on **guest, GET, 200** responses for public paths (auth surfaces, `/api`, the
   installer, feeds, sitemap are denylisted). An **authenticated** page never gets the flag, so the SW never
   stores a personal/PII page. Static shell assets (`/build`, `/icons`, fonts) are cache-first (no PII possible).

> Production should add 192/512 raster PNG icons for the widest install-prompt support; the maskable SVG covers
> modern browsers.

## Web Push (ADR-0058) — opt-in, cron-tolerant

A third notification channel — **push** — built FROM the existing notification system (`minishlink/web-push`,
MIT). The opt-in **is a device subscription**: the browser subscribes with the site's VAPID public key and POSTs
its subscription to `/push/subscribe`; the row's existence enables push for that device. `Notifier::send()` gains
one branch — when the recipient prefers push (default on once subscribed) AND has ≥ 1 subscription, it dispatches
a **queued** `SendPushNotification` job drained by the **baseline cron** `queue:work` (no persistent worker).
Absent a subscription nothing dispatches and in-app/email deliver unchanged (the **no-push fallback**). The job
builds the message via `PushPayload`, sends to every device, and **prunes** any subscription the push service
reports gone (410/404).

VAPID keys live in **encrypted settings**; generate them with `php artisan novfora:push:vapid` (refuses to
overwrite without `--force`, since rotation invalidates every subscription).

> **⚠ Delivery is scaffolded — not validated against a live push service.** No browser subscription / push
> endpoint exists in the build environment; the wiring is proven with a mocked sender. Validate end to end
> against a real browser + push service before relying on delivery.

## Push preferences UI (ADR-0059)

**Settings → Notifications** gains: a **Push** column in the per-event × per-channel matrix (per-type opt-in,
default on), and a **"Push notifications on this device"** card that drives the browser subscription via inline
Alpine (no asset rebuild) and **degrades silently** where unsupported or where the site has no VAPID keys.
Delivery requires BOTH a device subscription and the per-event push preference.

## How it works (for members)

**Install:** your browser offers "Install" / "Add to Home Screen" — NovFora opens like an app, and pages you've
visited still load offline. **Push:** open **Settings → Notifications**, tap **Enable on this device**, allow the
permission prompt, and pick which events push in the table. Turn it off per-device or per-event any time.
