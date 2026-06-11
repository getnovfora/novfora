<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Governance

How **NovFora** is governed: who decides what, how contributions are reviewed and accepted,
and how decisions are made. This is a working model for an early-stage project, with an explicit path to a
broader structure as the community grows. It is intentionally **concrete, not aspirational**.

## 1. Roles

| Role | Who | Rights | How you get it |
|---|---|---|---|
| **Lead Maintainer** | Project founder, initially | Final tie-breaker; stewards roadmap & releases; can resolve deadlocks | Founding role; later elected by Maintainers |
| **Maintainers** | Trusted long-term contributors | Merge rights; review/approve PRs; vote on RFCs; triage | Nominated by a Maintainer, **lazy-consensus** approval by the Maintainers, sustained quality track record |
| **Module/Theme stewards** | Owners of official modules/themes | Merge rights **scoped** to their area | Appointed by Maintainers for an area |
| **Contributors** | Anyone with a merged change | Propose PRs/issues/RFCs | Open a PR |
| **Users/Community** | Everyone | File issues, join discussion, vote with 👍 on RFCs (advisory) | — |

**Growth path:** once there are **≥5 active Maintainers from ≥3 unrelated organizations/individuals**, the
project transitions from Lead-Maintainer tie-break to a **Technical Steering Committee (TSC)** of elected
Maintainers (odd number, simple-majority vote, 1-year terms). This trigger is deliberate, so power formalizes
as the bus-factor improves.

## 2. Decision-making

- **Lazy consensus (default).** Most changes proceed if no Maintainer **objects** within the review window
  (≥72h for non-trivial PRs). Silence = assent.
- **RFC / ADR process (significant changes).** Anything that changes the **public module/theme API**, the
  **data model**, a **security model**, a **locked stack decision**, or governance itself requires a written
  **RFC** that becomes an **[ADR](DECISIONS.md)**. Flow: open RFC → **≥1 week** discussion → Maintainer vote.
  - Approval = **simple majority of Maintainers**, **no sustained objection** from another Maintainer.
  - A sustained, reasoned objection escalates to the **Lead Maintainer / TSC** to decide.
- **Roadmap is public.** Direction lives in [ROADMAP.md](ROADMAP.md); changes go through the RFC process for
  anything cross-phase.
- **Breaking changes to a semver'd public API (module/theme/REST)** require: an ADR, a **deprecation period of
  one full major**, and TSC/Lead-Maintainer sign-off. A breaking change is a **major-version event**.

## 3. Pull-request review & acceptance

Every change lands via PR — **including from Maintainers** (no direct pushes to `main`).

**Requirements to merge:**
1. **CI green** — Pint, PHPStan/Larastan, `composer audit`, the **Pest unit+feature** suites, **Dusk** where
   relevant, and the **asset/query budgets** ([testing-strategy](docs/architecture/testing-strategy.md)).
2. **Tests included** — "no feature is done without tests"; the **permission-mask** and **service-tier
   fallback** suites must cover relevant changes. Enforced by the PR template checklist.
3. **Reviews:**
   - **≥1 Maintainer approval** for ordinary changes.
   - **≥2 Maintainer approvals** for **security-sensitive** code, **public-API / module-theme-API** changes,
     **database migrations**, or the **permission/anti-spam** subsystems.
4. **Conventional Commits** + small, single-purpose changes.
5. **DCO sign-off** (`Signed-off-by:`) on every commit — see §5.
6. **SPDX header** (`SPDX-License-Identifier: Apache-2.0`) on new source files.

The PR author does **not** merge their own change; a reviewing Maintainer (or the area steward) merges.

## 4. Releases & security

- **Semantic Versioning** for the product and, separately, the **public module/theme/REST API contract**.
- **Release managers** rotate among Maintainers; each release is cut from `main`, tagged, with a generated
  changelog, and **upgrade-rehearsed on the baseline tier** before announcement.
- **Security disclosures** follow [SECURITY.md] (private report → fix → coordinated disclosure + patched
  release). A small **security response group** (subset of Maintainers) handles embargoed issues. Security
  fixes ship as patch releases to all supported lines.

## 5. Licensing & contributor sign-off

- All contributions are licensed under **Apache-2.0** (project license). New files carry an SPDX header.
- NovFora uses the **Developer Certificate of Origin (DCO)** — a per-commit `Signed-off-by:` line — **not a
  CLA**. The DCO is lighter-weight, keeps copyright with contributors, and suits a permissive Apache-2.0 project
  while still asserting provenance. (Recorded as a governance decision; revisitable via RFC.)
- **Clean-room is mandatory and reviewable:** no code/text/assets from any reference forum (commercial **or**
  open-source) may be contributed; importers copy *data*, never the source program. Reviewers reject suspected
  copied material.

## 6. Code of Conduct

All participation is governed by [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Enforcement is handled by a
**Conduct team** (initially the Lead Maintainer + one other Maintainer) via the contact listed there, following
the documented enforcement ladder. Conduct decisions are appealable to the full Maintainer group.

## 7. Changing this document

Governance changes go through the **RFC process** and require a **two-thirds majority of Maintainers** (and,
once formed, the TSC). The current model errs toward simplicity; it is expected to formalize as NovFora grows,
and that evolution is itself governed by this section.
