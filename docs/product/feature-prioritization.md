# Feature Prioritization (MoSCoW)

> **Project:** Hearth (working codename). **Stage A deliverable** (Section 8 #7). **Date:** 2026-06-01.
> The full Section-6 feature surface tagged **Must / Should / Could / Won't-for-now**, with the delivery phase
> and notes. **Must = the MVP** ([mvp-scope](mvp-scope.md)); phases are defined in [roadmap](roadmap.md).
> Tagging reflects the evidence in [community-complaints](../research/community-complaints-and-feature-requests.md):
> the four "everyone-gets-this-wrong" areas — **spam, search, upgrade/theming fragility, migration** — are
> pulled forward or made Must even where a naive MVP would defer them.

**Legend:** **M** = Must (MVP) · **S** = Should · **C** = Could · **W** = Won't-for-now. Phase per [roadmap](roadmap.md).

---

## Core structure
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Categories → forums → topics → posts (unlimited nesting, ordering, per-node permissions) | **M** | 1 | The spine; server-rendered |
| Sticky / announcement / locked / moved | **M** | 1 | State on `topics` |
| Merge / split topics | **S** | 2 | Moderation tooling |
| Soft-delete + recycle bin + restore | **M** | 1 | `deleted_at` + restore |
| Audit trail | **M** | 1 | Security baseline |
| Polls | **S** | 2 | `polls` modeled in MVP, UI Phase 2 |
| Topic prefixes (XenForo) | **S** | 2 | `topic_prefixes` |
| Tags (Discourse) coexisting with categories | **S** | 2 | `tags`/`taggables` |

## Permissions & groups (phpBB-grade ACL)
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Permission-mask engine (ALLOW/NO/NEVER, global→forum scope) | **M** | 1 | Primary requirement (ADR-0006) |
| Primary + secondary group membership | **M** | 1 | |
| Role presets | **M** | 1 | Seeded presets (admin/mod/member/guest) |
| "Why can/can't X" inspector | **S** | 1–2 | Cheap, high-value; build alongside the engine |
| Automatic group promotion (post/trust/time) | **S** | 2 | Also the trust-level mechanism |
| Per-group styling | **C** | 3 | |

## Posting & content (WYSIWYG-first)
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| WYSIWYG editor (TipTap-class, @mentions, drag-drop/paste upload) | **M** | 1 | #1 technical-risk spike (ADR-0012) |
| Markdown input mode | **S** | 1–2 | Power-user toggle; MVP can ship WYSIWYG-only |
| BBCode compatibility layer (import + optional input) | **S** | 3 | Needed with importers |
| Canonical sanitized storage + safe render | **M** | 1 | ADR-0005 |
| Attachments + thumbnailing | **M** | 1 | Local disk baseline |
| Drafts / autosave | **S** | 2 | |
| Native oEmbed embedding | **S** | 2 | |
| Code blocks / spoilers / quotes | **S** | 1–2 | Editor nodes |
| Reactions / likes | **S** | 2 | XF reaction-score model |
| Edit history + diffs | **S** | 2 | `post_revisions` |

## Users & social
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Email-verified registration (optional admin approval) | **M** | 1 | Anti-spam gate |
| 2FA / TOTP — admin & moderator accounts | **M** | 1 | Privileged-account hardening (staff) |
| 2FA / TOTP — general users (opt-in) | **S** | 2 | Opt-in account security |
| Rich profiles | **M** | 1 | Basic in MVP |
| Custom profile fields | **S** | 2 | |
| Avatars / covers | **S** | 1–2 | Avatar basic MVP |
| Signatures | **C** | 2 | |
| Trust levels (Discourse-style) | **M** | 1 | Anti-spam lever + groups (ADR-0007) |
| Reputation / points | **S** | 2 | |
| Badges / trophies / achievements | **S** | 2 | Criteria engine (XF concept) |
| Follow / ignore | **S** | 2 | |
| Activity feeds | **S** | 2 | |
| Presence | **C** | 4 | Real-time (enhanced) |
| Staff notes | **S** | 2 | |

## Messaging & notifications
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Email notifications (queued) | **M** | 1 | Deliverability per ADR-0014 |
| In-app notifications | **M** | 1 | Polling baseline; merge-aware |
| Multi-participant PMs | **S** | 2 | |
| Granular email prefs | **S** | 2 | |
| Digest emails | **S** | 2 | Deliverability + volume hygiene |
| Web push | **C** | 4 | Enhanced / PWA |

## Moderation & admin
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| ACP + MCP | **M** | 1 | Admin + mod control panels |
| Moderation queue | **M** | 1 | |
| Inline moderation in thread view | **S** | 2 | + cross-page bulk select (XF) |
| Report system | **S** | 2 | |
| Warnings / infractions + auto-consequences + decay + ack | **S** | 2 | XF/IPS concepts |
| Bans (user/IP/email/range) | **M** | 1 | Basic in MVP |
| Word filters | **S** | 2 | |
| Approval workflows | **M** | 1 | `approved_state` (anti-spam) |
| Complete audit log | **M** | 1 | |
| Staff dashboards | **S** | 3 | |

## Anti-spam (first-class — ADR-0007)
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Crowdsourced blocklist (StopForumSpam-style) | **M** | 1 | Cron-cached; graceful |
| Provider-swappable CAPTCHA (Q&A + invisible + pluggable) | **M** | 1 | Q&A is baseline-safe |
| Honeypots + timing | **M** | 1 | Local |
| Rate limiting (trust-tiered) | **M** | 1 | DB baseline / Redis enhanced |
| New-user moderation queue + trust gating | **M** | 1 | Unified with ACL |
| Disposable-email blocking | **M** | 1 | |
| Content scanning (Akismet-pluggable) | **S** | 2 | Scanning **contract** designed in P1 (anti-spam baseline); **Akismet provider** integration ships P2 |
| IP risk / velocity (MaxMind) + AI scoring | **C** | 4 | Enhanced intelligence |

## Search & discovery
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Search via Scout DB driver (MySQL FT) | **M** | 1 | Baseline; threshold documented |
| Inline predictive results + filters/facets | **S** | 2–3 | |
| Meilisearch/Typesense (enhanced) | **C** | 4 | Same UX, swap driver |
| Per-user unread / "what's new" | **M** | 1 | Watermark design |
| Similar topics / trending | **C** | 3–4 | |

## SEO & performance
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Server-rendered pages | **M** | 1 | Core stack property |
| Canonical URLs + human slugs | **M** | 1 | |
| `schema.org DiscussionForumPosting` + OG tags | **M** | 1 | |
| XML sitemaps + smart `noindex` filtering | **S** | 1–3 | Basic sitemap MVP |
| Import redirect maps | **S** | 3 | With importers (C5) |
| Response + fragment caching | **M** | 1 | Budgets in system-arch |
| CDN-friendly assets / lazy loading | **S** | 4 | Path to large |

## Theming & layout
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Theme foundation + Blade override layer (no core edits) | **M** | 1 | ADR-0009 |
| Mobile-first responsive default theme | **M** | 1 | Complaint C2 |
| Light / dark | **S** | 1–3 | Token sets |
| Visual point-and-click configurator (style tokens, live preview) | **S** | 3 | ProBoards north star |
| Drag-to-arrange layout/widgets | **C** | 3 | Slot system |
| Per-forum styling | **C** | 3 | |
| Theme a11y floor (contrast/keyboard) | **M** | 1 | Baked in (ADR-0016) |

## Monetization
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Paid memberships / subscriptions (Stripe) | **S** | 4 | subscription→group→permission (IPS) |
| Paid user upgrades | **C** | 4 | |
| Pluggable payments | **C** | 4 | Cashier |
| Ad-slot management | **C** | 4 | |

## Integrations & API
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Public REST API (versioned) | **S** | 3 | Sanctum tokens |
| Webhooks | **S** | 3 | On domain events |
| SSO (OAuth2/OIDC, SAML, magic-link) | **C** | 4 | Passport; forum-as-OAuth2-provider |
| Embeddable comment/widget mode | **W** | — | Post-1.0 |
| Chat bridges (Discord/Matrix) as modules | **W** | — | Community modules |

## Migration / import
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Resumable importers: phpBB / MyBB / SMF (verify + redirects) | **S** | 3 | High priority; ADR-0013, C7 |
| XenForo importer | **C** | 3–4 | Stretch |

## Analytics
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Admin dashboards (registrations, posts, active users, spam stats) | **S** | 3 | |
| Device breakdown / top content | **C** | 3–4 | |

## Operability (elevated to Must — self-hosting)
| Feature | MoSCoW | Phase | Notes |
|---|:--:|:--:|---|
| Composer + **web installer (no SSH required)** | **M** | 1 | |
| Deployment-tier detection | **M** | 1 | ADR-0003 |
| Automated backups | **M** | 1 | |
| Health checks | **S** | 1–3 | |
| Safe in-place upgrades via reversible migrations | **M** | 1 | Brief hard rule |
| Prebuilt assets (no Node on host) | **M** | 1 | |
| Module pre-upgrade compatibility check | **S** | 3 | With module API |
| Docker / compose (enhanced) | **S** | 4 | |

## Cross-cutting (Must, every phase)
Security baseline (OWASP, argon2id, CSP, CSRF) · reversible non-destructive migrations · **tests with every
feature** (permissions + tier fallbacks dedicated) · progressive enhancement / graceful degradation ·
**accessibility baseline (WCAG 2.1 AA)** · i18n-ready (utf8mb4, externalized strings, RTL not precluded) ·
structured logging / observability.

---

## Won't-for-now (explicit non-goals for 1.0)
Multi-tenant SaaS (seam kept, not built) · embeddable comment widget · native mobile apps (PWA instead) ·
chat bridges in core (modules) · marketplace payments/escrow · AI content generation. These are documented so
scope stays honest; the architecture precludes none of them.
