// SPDX-License-Identifier: Apache-2.0
//
// k6 read-path load test for NovFora (Wave 8.3 harness).
//
// Drives the high-traffic GUEST read surfaces — board index, a forum listing, a topic, and search — against
// a board already populated by `php artisan novfora:loadtest:seed`. This is the DRIVER half of the harness;
// it ships no validated numbers. Set thresholds and VU/duration to YOUR target and measure on YOUR hardware.
//
//   k6 run -e BASE_URL=https://your-host -e FORUMS=5 -e TOPICS=40 load-tests/k6/browse.js
//
// Notes:
//   * Read-only and guest-only by design — safe to point at a staging copy. It does NOT log in or write.
//   * The seed creates forum slugs loadtest-forum-{0..FORUMS-1}; topic/post ids are discovered by crawling
//     the board, so this stays correct regardless of pre-existing content.

import http from 'k6/http';
import { check, sleep, group } from 'k6';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost').replace(/\/$/, '');
const FORUMS = parseInt(__ENV.FORUMS || '5', 10);

export const options = {
  // Conservative default shape — OVERRIDE for a real run. These are starting points, not validated SLOs.
  stages: [
    { duration: __ENV.RAMP || '30s', target: parseInt(__ENV.VUS || '20', 10) },
    { duration: __ENV.HOLD || '1m', target: parseInt(__ENV.VUS || '20', 10) },
    { duration: '15s', target: 0 },
  ],
  thresholds: {
    // Placeholder gates — tune to your target. A failed threshold makes k6 exit non-zero (CI-gateable).
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800'],
  },
};

// Extract the first N topic links from a board/forum HTML page (/topics/{id}).
function topicIdsFrom(body) {
  const ids = [];
  const re = /\/topics\/(\d+)/g;
  let m;
  while ((m = re.exec(body)) !== null && ids.length < 10) {
    if (!ids.includes(m[1])) ids.push(m[1]);
  }
  return ids;
}

export default function () {
  group('board index', () => {
    const res = http.get(`${BASE_URL}/forums`);
    check(res, { 'board 200': (r) => r.status === 200 });
  });

  const f = Math.floor(Math.random() * FORUMS);
  let topicIds = [];
  group('forum listing', () => {
    const res = http.get(`${BASE_URL}/forums/loadtest-forum-${f}`);
    check(res, { 'forum 200': (r) => r.status === 200 });
    if (res.status === 200) topicIds = topicIdsFrom(res.body);
  });

  if (topicIds.length > 0) {
    group('topic view', () => {
      const id = topicIds[Math.floor(Math.random() * topicIds.length)];
      const res = http.get(`${BASE_URL}/topics/${id}`);
      check(res, { 'topic 200': (r) => r.status === 200 });
    });
  }

  group('search', () => {
    const terms = ['latency', 'throughput', 'permission', 'theme', 'digest'];
    const q = terms[Math.floor(Math.random() * terms.length)];
    const res = http.get(`${BASE_URL}/search?q=${q}`);
    check(res, { 'search 200': (r) => r.status === 200 });
  });

  sleep(1);
}
