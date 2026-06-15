<!-- SPDX-License-Identifier: Apache-2.0 -->
# Advanced anti-spam intelligence (Phase 4 · M6)

> ADR-0067 (scoring), ADR-0068 (review surface), ADR-0069 (external-signal tuning + privacy).

## How it works (for operators)

NovFora scores new posts for spam using behavioural signals and **holds** suspicious ones for the
moderation queue — it **never auto-deletes**. Review them in **Admin → Moderation → Spam intelligence**:
each held post shows its **score**, the **signals** that fired, and the reasons, with **Approve**
(false positive) and **Reject** (genuine spam → soft-deleted to the recycle bin) actions.

**Trusted members are exempt** — staff, established members, and high-trust accounts are never held by
the scorer (the false-positive guard). Tune the StopForumSpam block threshold and the new-user controls
in **Admin → Settings → Anti-spam**.

**Privacy:** by default, only **metadata** (IP, email, username) is ever sent to StopForumSpam — **never
the text of members' posts**. Sending post content to a third party requires an explicit opt-in
(Anti-spam settings), and even then it's only used as spam evidence when reporting a confirmed spammer.

## How it works (for developers)

- `App\AntiSpam\Intelligence\SpamScorer` adds a step to `ContentModerator::review()`. It is **HOLD-only**
  (the orchestrator caps it at HOLD). Signals: **similarity** (author reposted near-identical content —
  normalised-fingerprint match), **burst** (post velocity over a window, beyond the rate limiter),
  **new-account**, **tl0**. Config in `config/novfora.php → antispam.intelligence`.
- **FP guards:** trusted members (staff / `trusted_floor` / `established_posts`) are never scored; short
  content (< 12 fingerprint chars) never triggers similarity; a new member's first normal post is below
  threshold.
- A held post records a `spam_assessments` row (score + per-signal breakdown + reasons) for the review
  surface, plus a `post.spam_held` audit entry.
- `App\AntiSpam\ExternalSignalPolicy` centralises external-signal control: `apiEnabled()`,
  `confidenceThreshold()` (admin-tunable; `RegistrationGuard` reads it), `maySubmitContent()` (the
  privacy fence, **default false**), `apiKey()` (encrypted). `App\AntiSpam\SpamReporter` is inert unless
  the API is enabled AND a key is set; post content is included only with the content opt-in.

## ⚠ SCAFFOLDED — NOT VALIDATED against the live StopForumSpam submission API

`SpamReporter` is proven with a mocked HTTP client; no submission key is in the build, so it makes no
real outbound call. **To enable spammer reporting:** set the StopForumSpam submission key + turn on the
live API in Admin → Settings → Anti-spam; leave "send post content" off unless your community consents.
The scoring/holding pipeline (M6.1) and the review surface (M6.2) are fully real and validated.
