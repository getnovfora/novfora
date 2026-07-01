/* SPDX-License-Identifier: Apache-2.0 */
/*
 * NovFora embed web components — v1.0.0 (U7, ADR-0103; the element names, attributes, and JSON shape
 * are a semver'd public contract). Dependency-free classic script; include it from the forum it embeds:
 *
 *   <script src="https://forum.example.com/embed/embed.js" defer></script>
 *   <novfora-topics site="emb_…" forum="3" limit="5" theme="auto"></novfora-topics>
 *   <novfora-stats  site="emb_…" theme="light"></novfora-stats>
 *
 * The API base is derived from the script's own src, so subdirectory installs work unchanged. All payload
 * fields are rendered as TEXT nodes inside a closed Shadow DOM — never as HTML.
 */
(function () {
    'use strict';

    var current = document.currentScript;
    if (!current || !current.src) {
        return;
    }
    var BASE = current.src.replace(/\/embed\/embed\.js(\?.*)?$/, '');

    var STYLE = [
        ':host{display:block;font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;',
        'color:#1c1b18;background:#fcfaf4;border:1px solid #e3ded2;border-radius:8px;padding:12px}',
        ':host([theme="dark"]){color:#ece9e2;background:#0b0b10;border-color:#2a2a33}',
        'a{color:#1d4ed8;text-decoration:none}:host([theme="dark"]) a{color:#7ca5f5}',
        'a:hover{text-decoration:underline}',
        'a:focus-visible{outline:2px solid currentColor;outline-offset:2px}',
        'h2{font-size:15px;margin:0 0 8px}',
        'ul{list-style:none;margin:0;padding:0}',
        'li{padding:6px 0;border-top:1px solid #e3ded2}:host([theme="dark"]) li{border-top-color:#2a2a33}',
        'li:first-child{border-top:0}',
        'time,.muted{opacity:.7;font-size:12px;display:block}',
        'dl{display:flex;gap:20px;margin:0}dt{opacity:.7;font-size:12px}dd{margin:0;font-size:18px;font-weight:600}',
    ].join('');

    function applyAutoTheme(el) {
        if ((el.getAttribute('theme') || 'auto') !== 'auto' || !window.matchMedia) {
            return;
        }
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var sync = function () { el.setAttribute('theme', mq.matches ? 'dark' : 'light'); };
        sync();
    }

    function widgetBase(widget) {
        return class extends HTMLElement {
            connectedCallback() {
                if (this.__nvfLoaded) {
                    return;
                }
                this.__nvfLoaded = true;
                applyAutoTheme(this);

                var root = this.attachShadow({ mode: 'closed' });
                var style = document.createElement('style');
                style.textContent = STYLE;
                root.appendChild(style);

                var site = this.getAttribute('site') || '';
                var params = new URLSearchParams({ site: site });
                if (widget === 'topics') {
                    var forum = this.getAttribute('forum');
                    if (forum) { params.set('forum', forum); }
                    var limit = this.getAttribute('limit');
                    if (limit) { params.set('limit', limit); }
                }

                var el = this;
                fetch(BASE + '/embed/v1/d/' + widget + '.json?' + params.toString(), { credentials: 'omit' })
                    .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                    .then(function (json) { el.__nvfRender(root, json.data || {}); })
                    .catch(function () { /* render nothing — an unreachable forum must not break the host page */ });
            }

            __nvfRender(root, data) {
                var h2 = document.createElement('h2');
                var link = document.createElement('a');
                link.href = data.url || '#';
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = data.title || '';
                h2.appendChild(link);
                root.appendChild(h2);

                if (widget === 'stats') {
                    var dl = document.createElement('dl');
                    [['Members', data.members], ['Topics', data.topics], ['Posts', data.posts]].forEach(function (pair) {
                        var wrap = document.createElement('div');
                        var dt = document.createElement('dt');
                        dt.textContent = pair[0];
                        var dd = document.createElement('dd');
                        dd.textContent = typeof pair[1] === 'number' ? pair[1].toLocaleString() : '0';
                        wrap.appendChild(dt);
                        wrap.appendChild(dd);
                        dl.appendChild(wrap);
                    });
                    root.appendChild(dl);
                    return;
                }

                var items = Array.isArray(data.items) ? data.items : [];
                if (items.length === 0) {
                    var p = document.createElement('p');
                    p.className = 'muted';
                    p.textContent = 'No topics yet.';
                    root.appendChild(p);
                    return;
                }
                var ul = document.createElement('ul');
                items.forEach(function (item) {
                    var li = document.createElement('li');
                    var a = document.createElement('a');
                    a.href = typeof item.url === 'string' ? item.url : '#';
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.textContent = typeof item.title === 'string' ? item.title : '';
                    li.appendChild(a);
                    if (item.posted_at) {
                        var t = document.createElement('time');
                        t.setAttribute('datetime', item.posted_at);
                        t.textContent = new Date(item.posted_at).toLocaleDateString();
                        li.appendChild(t);
                    }
                    ul.appendChild(li);
                });
                root.appendChild(ul);
            }
        };
    }

    if (window.customElements) {
        if (!customElements.get('novfora-topics')) {
            customElements.define('novfora-topics', widgetBase('topics'));
        }
        if (!customElements.get('novfora-stats')) {
            customElements.define('novfora-stats', widgetBase('stats'));
        }
    }
})();
