// SPDX-License-Identifier: Apache-2.0
// NovFora service worker (Phase 4 · M3.1, hardened NF-05). Caches the app SHELL (static build assets +
// icons) and the offline fallback for offline READ; caches a visited page's HTML ONLY when the server marked
// it safe with the `X-PWA-Cacheable` header (set for guest, no-PII pages — see PwaResponseHeaders). It NEVER
// touches non-GET requests, so authenticated MUTATIONS (POST/PUT/PATCH/DELETE) are never cached and never
// replayed, and it never stores a personal/authenticated page. Bump CACHE_VERSION to invalidate all caches
// on deploy.
//
// NF-05 (NOV-148) privacy fences:
//   • navigations are classified FIRST and only ever go through the X-PWA-Cacheable page path — a URL that
//     merely LOOKS like an asset (e.g. a crafted "/topics/1-x.png" that redirects to an HTML page) can no
//     longer slip a personal page into the asset cache;
//   • the asset cache is an ALLOWLIST of the real public static directories/files under the mount, never an
//     arbitrary filename extension;
//   • an asset response is stored only if it is a same-origin, non-redirected 200 that is not HTML;
//   • activation deletes only NovFora-owned caches from older versions (the `novfora-pwa-` namespace) and
//     leaves any other Cache API entries on the origin alone. v3 retires every v2 cache, which may hold
//     entries stored under the old extension rule.

const CACHE_NAMESPACE = 'novfora-pwa-';
const CACHE_VERSION = CACHE_NAMESPACE + 'v3';
const SHELL_CACHE = CACHE_VERSION + '-shell';
const PAGE_CACHE = CACHE_VERSION + '-pages';
// Derive the mount base from the registration scope (ADR-0078) so the SW is correct under any mount with NO
// server templating: "/" at a domain root, "/community/" under a subdirectory mount. Every cached prefix +
// the offline fallback below are made scope-relative from it.
const SCOPE = new URL(self.registration.scope).pathname;
const OFFLINE_URL = SCOPE + 'offline';

// The public, immutable static surface — Vite's hashed build output + the app icons + the favicons. Nothing
// else (no app route, no upload, no user avatar) is ever eligible for the asset cache.
const ASSET_DIRS = [SCOPE + 'build/', SCOPE + 'icons/'];
const ASSET_FILES = [SCOPE + 'favicon.ico', SCOPE + 'favicon.svg'];

function isStaticAsset(pathname) {
  return ASSET_DIRS.some((dir) => pathname.startsWith(dir)) || ASSET_FILES.includes(pathname);
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((k) => k.startsWith(CACHE_NAMESPACE) && !k.startsWith(CACHE_VERSION))
          .map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only ever handle GET. Every mutation passes straight through to the network — never cached, never replayed.
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // same-origin only

  // Page navigations FIRST → network-first; cache only server-blessed (guest, no-PII) pages; fall back to
  // /offline. Whatever the URL looks like, a navigation never reaches the asset cache.
  if (req.mode === 'navigate') {
    event.respondWith(networkFirstPage(req));
    return;
  }

  // Allowlisted static assets → cache-first (public, immutable, no PII). Everything else goes to the network.
  if (isStaticAsset(url.pathname)) {
    event.respondWith(cacheFirst(req));
  }
});

function isCacheableAsset(res) {
  if (!res || res.status !== 200 || res.type !== 'basic' || res.redirected) return false;
  const type = (res.headers.get('Content-Type') || '').toLowerCase();
  return !type.startsWith('text/html');
}

async function cacheFirst(req) {
  const cache = await caches.open(SHELL_CACHE);
  const cached = await cache.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    if (isCacheableAsset(res)) {
      cache.put(req, res.clone());
    }
    return res;
  } catch (err) {
    return Response.error();
  }
}

async function networkFirstPage(req) {
  try {
    const res = await fetch(req);
    // Cache the page ONLY when the server flagged it cacheable (guest + no PII). Authenticated/personal pages
    // never carry this header, so they are never stored.
    if (res && res.status === 200 && res.headers.get('X-PWA-Cacheable') === '1') {
      const cache = await caches.open(PAGE_CACHE);
      cache.put(req, res.clone());
    }
    return res;
  } catch (err) {
    const pages = await caches.open(PAGE_CACHE);
    const shell = await caches.open(SHELL_CACHE);
    return (await pages.match(req)) || (await shell.match(OFFLINE_URL)) || Response.error();
  }
}
