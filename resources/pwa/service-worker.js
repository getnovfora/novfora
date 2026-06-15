// SPDX-License-Identifier: Apache-2.0
// NovFora service worker (Phase 4 · M3.1). Caches the app SHELL (static build assets + fonts/icons) and the
// offline fallback for offline READ; caches a visited page's HTML ONLY when the server marked it safe with the
// `X-PWA-Cacheable` header (set for guest, no-PII pages — see PwaResponseHeaders). It NEVER touches non-GET
// requests, so authenticated MUTATIONS (POST/PUT/PATCH/DELETE) are never cached and never replayed, and it
// never stores a personal/authenticated page. Bump CACHE_VERSION to invalidate all caches on deploy.

const CACHE_VERSION = 'novfora-pwa-v1';
const SHELL_CACHE = CACHE_VERSION + '-shell';
const PAGE_CACHE = CACHE_VERSION + '-pages';
const OFFLINE_URL = '/offline';

const ASSET_RE = /\.(?:css|js|mjs|woff2?|ttf|png|svg|ico|jpe?g|webp|gif|avif)$/;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only ever handle GET. Every mutation passes straight through to the network — never cached, never replayed.
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // same-origin only

  // Static, public, immutable assets → cache-first (no PII possible).
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || ASSET_RE.test(url.pathname)) {
    event.respondWith(cacheFirst(req));
    return;
  }

  // Page navigations → network-first; cache only server-blessed (guest, no-PII) pages; fall back to /offline.
  if (req.mode === 'navigate') {
    event.respondWith(networkFirstPage(req));
  }
});

async function cacheFirst(req) {
  const cached = await caches.match(req);
  if (cached) return cached;
  try {
    const res = await fetch(req);
    if (res && res.status === 200) {
      const cache = await caches.open(SHELL_CACHE);
      cache.put(req, res.clone());
    }
    return res;
  } catch (err) {
    return cached || Response.error();
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
    const cached = await caches.match(req);
    return cached || (await caches.match(OFFLINE_URL)) || Response.error();
  }
}
