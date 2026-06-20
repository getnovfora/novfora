# <Handoff title> — <one-line what>

> **Handoff spec.** Written in Cowork (planning), executed by Code (build + gates + commit) on the
> real machine. Fill every section; delete the guidance in _italics_. If a section is genuinely N/A,
> write "N/A — <reason>" rather than removing it. This file is the contract — Code should run it cold.

## 1. Goal
_One paragraph: what we're building and why now. Link the roadmap/ADR item it implements._

## 2. Scope / Non-goals
**In scope:** _the specific work to land in this pass._
**Non-goals:** _what to explicitly leave out (these double as merge-safety boundaries — files/areas other
lanes own that this one must not touch)._

## 3. Locked constraints
_Restate the relevant CLAUDE.md rules inline so Code won't relitigate. Pull only what applies, e.g.:_
- Clean-room: reimplement independently; never copy reference-forum code/templates/UI.
- Progressive enhancement: no Baseline feature hard-depends on Redis/WebSocket/worker/external search.
- Reuse the existing permission engine — no second permission system.
- Reversible, non-destructive migrations; idempotent (guard `Schema::create`).
- Tests with the feature; permission-mask + service-tier fallbacks get dedicated tests.

## 4. Files to touch
_Exact paths, in execution order. Mark new vs. edit. Call out any file that must NOT change._

## 5. Sequence
_Numbered steps. Each step ends with its verification (which gate, what "pass" looks like)._
1.
2.
3.

## 6. Verification / done criteria
_The gates that must be green before commit. Cap output with `tail -n N` / `Select-Object -Last N`._
- `./vendor/bin/pest` (and any feature/Dusk subset)
- `./vendor/bin/pint --test`
- `./vendor/bin/phpstan` (Larastan)
- `composer audit`
- _Load-bearing lane? (permissions / concurrency / untrusted input) → mandatory adversarial-review
  pass before merge. Gates alone are not sufficient here._

## 7. Commit
_Exact conventional-commit line(s), `-s` (DCO), authored as Tommy Huynh <tommy@saturnhq.net>.
Small, reviewable commits — one logical change each._
```bash
git add <paths>
git commit -s -m "<type>(<scope>): <subject>"
```

---
Read docs/product/<this-file>.md and execute it.
