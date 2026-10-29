// SPDX-License-Identifier: Apache-2.0
//
// NF-05 (NOV-148) real-browser service-worker privacy harness — for staging / pre-release validation.
// Drives the NovFora service worker in headless Chromium (Playwright) and reports whether a member's private
// page can end up in the Cache API, whether a guest can replay it, whether unrelated Cache API entries
// survive activation, and whether offline read still works. Run it against a domain-root mount AND a
// subdirectory mount (e.g. /community).
//
// Prerequisites on the target: a verified member sw@test.local / Password-123456 who authored topic <topicId>
// whose body contains PRIVATE-MARKER-123, and a forum with slug "general".
//
// Usage: node scripts/pwa-sw-harness.cjs http://127.0.0.1:8000[/community] <topicId>
// Expect: unrelatedCacheKept=true, staleV2Deleted=true, anyCachedEntryLeaksPrivateHtml=false,
//         htmlInShellCache=[], guestSubresourceReplayServesMemberPage=false, offline*Served=true.
const { chromium } = require('playwright');

const BASE = process.argv[2];
const TOPIC = process.argv[3];
const MARKER = 'PRIVATE-MARKER-123';

async function cacheReport(page) {
  return page.evaluate(async (marker) => {
    const out = {};
    for (const name of await caches.keys()) {
      const c = await caches.open(name);
      const entries = [];
      for (const req of await c.keys()) {
        const res = await c.match(req);
        const body = await res.clone().text();
        entries.push({ url: new URL(req.url).pathname, html: (res.headers.get('content-type') || '').includes('html'), leak: body.includes(marker) });
      }
      out[name] = entries;
    }
    return out;
  }, MARKER);
}

async function waitForController(page) {
  await page.evaluate(() => navigator.serviceWorker.ready);
  if (!(await page.evaluate(() => !!navigator.serviceWorker.controller))) {
    await page.reload();
    await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, { timeout: 15000 });
  }
}

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();
  const results = {};

  // Seed an unrelated Cache API entry + a stale NovFora v2 cache BEFORE the worker installs.
  await page.goto(BASE + '/offline');
  await page.evaluate(async () => {
    await (await caches.open('other-app-cache')).put('/other-app/data', new Response('keep me'));
    await (await caches.open('novfora-pwa-v2-shell')).put('/stale', new Response('stale v2'));
  });

  // Register + take control.
  await page.goto(BASE + '/');
  await waitForController(page);
  await page.waitForTimeout(500);
  const afterActivate = await page.evaluate(async () => caches.keys());
  results.unrelatedCacheKept = afterActivate.includes('other-app-cache');
  results.staleV2Deleted = !afterActivate.includes('novfora-pwa-v2-shell');

  // Sign in.
  await page.goto(BASE + '/login');
  await page.fill('input[name=email]', 'sw@test.local');
  await page.fill('input[name=password]', 'Password-123456');
  await Promise.all([page.waitForNavigation(), page.click('form[action$="/login"] button[type=submit]')]);

  // Authenticated: an asset-LOOKING URL that resolves (301 → canonical) to a private HTML page, as a navigation
  // and as a subresource fetch.
  await page.goto(`${BASE}/topics/${TOPIC}-evil.png`);
  results.authNavigationShowsMarker = (await page.content()).includes(MARKER);
  await page.evaluate(async (u) => { try { await fetch(u); } catch (e) {} }, `${BASE}/topics/${TOPIC}-evil2.css`);
  await page.waitForTimeout(500);

  const report = await cacheReport(page);
  results.anyCachedEntryLeaksPrivateHtml = Object.values(report).flat().some((e) => e.leak);
  results.htmlInShellCache = Object.entries(report).filter(([k]) => k.endsWith('-shell')).flatMap(([, v]) => v).filter((e) => e.html && !e.url.endsWith('/offline')).map((e) => e.url);
  results.buildAssetsCached = Object.entries(report).filter(([k]) => k.endsWith('-shell')).flatMap(([, v]) => v).some((e) => e.url.includes('/build/'));

  // Sign out (drop the session) and replay the crafted URL as a guest: must NOT serve the member's page.
  await context.clearCookies();
  await page.goto(`${BASE}/topics/${TOPIC}-evil.png`);
  const guestBody = await page.content();
  results.guestReplayLeaksMarker = guestBody.includes(MARKER) && !guestBody.includes('Sign in to reply');
  const guestFetch = await page.evaluate(async (u) => (await fetch(u)).text(), `${BASE}/topics/${TOPIC}-evil2.css`);
  results.guestSubresourceReplayServesMemberPage = guestFetch.includes(MARKER) && guestFetch.includes('swuser') && !guestFetch.includes('Sign in to reply');

  // Offline replay: a guest-cached public page comes back from the page cache; an uncached page → /offline.
  await page.goto(BASE + '/forums/general');
  await page.waitForTimeout(300);
  await context.setOffline(true);
  await page.goto(BASE + '/forums/general');
  results.offlineGuestPageServed = (await page.content()).includes('General');
  await page.goto(BASE + '/members');
  results.offlineFallbackServed = (await page.content()).toLowerCase().includes('offline');
  await context.setOffline(false);

  console.log(JSON.stringify({ results, report }, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
