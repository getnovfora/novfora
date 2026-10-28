<!-- SPDX-License-Identifier: Apache-2.0 -->

# Spike memo — NOV-124: the NovFora Registry v1 (2026-07-18)

> Kickoff §5 (4C) mandates this spike self-gate on four criteria before the Registry slice may build.
> **VERDICT: GO** — every criterion is met structurally, not aspirationally. Detail below.

## 1. The feed — a static, signed document

- **`registry.json`** + detached **`registry.json.sig`** (ed25519). Served from novfora.com/registry/
  (or any mirror — the signature, not the host, is the authority). Nothing executes server-side:
  generation is an ops-side signing script; the file is byte-stable and CDN/mirror-friendly.
- **Signed envelope fields:** `schema_version` (1), `sequence` (monotonic integer, bumped every
  publish), `generated_at` (ISO), `packages[]`, `publishers[]`.
- **Feed trust root:** ONE registry root public key **pinned in shipped config**
  (`novfora.registry.root_key`). The .sig verifies the exact bytes of registry.json against that pin.
  A mirror cannot alter, reorder, or omit without breaking the signature.
- **Feed freshness/rollback:** the client stores `last_seen_sequence`; a verified feed with a LOWER
  sequence is refused (rollback attack), equal is a no-op, higher is accepted. A feed older than N days
  (config, default 30) surfaces a staleness warning in the ACP (mirror freeze detection).

## 2. Packages + publishers in the feed

- **`publishers[]`:** `{fingerprint, public_key_b64, name, status: active|revoked, revoked_at?,
  revocation_reason?}` — the SAME ed25519 key format `module_trust_keys`/`PackageSignature` already
  verify. Enrollment/rotation v1 is an off-product, human process (curated first-party registry);
  rotation = enrol new key + revoke old (both listed; packages re-signed with the new key).
- **`packages[]`:** `{slug (vendor/name), type: module|theme, title, description, latest,
  versions[]: {version, api_version, zip_url, sha256, size, publisher_fingerprint, released_at}}`.
- **Typosquat posture v1:** curated enrollment (no self-serve publishing), vendor-namespaced slugs,
  similarity screening is an enrollment-time human check. Recorded as v1 posture in the ADR.

## 3. The client — rides the existing trust machinery UNTOUCHED

Install flow (ACP → Browse):
1. Cron (daily, baseline-safe) or manual refresh: fetch feed + sig → `PackageSignature`-class ed25519
   verify against the PINNED root key → sequence check → cache the verified document locally.
2. One-click install: download zip (primary URL or mirror) → **sha256 must equal the feed's pin**
   (content-addressed: a mirror substituting bytes fails here) → ensure the publisher key is `active`
   in the feed → ensure that key exists+enabled in `module_trust_keys` (registry-managed rows, added on
   first use, flagged `source=registry`) → hand the zip to **`ModuleInstaller::installFromZip` — the
   ONE install path** (ArchiveGuard extraction hardening + PackageSignature verify + quarantine).
   No second path, no `allow_unsigned` interaction (unsigned is never listed, and an unsigned zip is
   rejected by the existing gate anyway).
3. **Package downgrade refusal:** the one-click path never offers/installs a version lower than the
   installed one; `ModuleInstaller` upgrade flow is versioned by `module.json` (slug from manifest,
   never the filename/feed).

## 4. Revocation — the hard part (criterion 1)

Honest model: code already running cannot be un-run; revocation must (a) cut off the future, (b) surface
the present, loudly, with a safe response path.
1. On every verified feed refresh, publishers with `status: revoked` have their **registry-managed
   `module_trust_keys` rows disabled** → `PackageSignature::verify` no longer accepts them → **no new
   install and no UPGRADE of any package signed by that key can ever pass** (the upgrade path is the
   same installFromZip gate). This is "propagates to the upgrade path of already-installed packages".
2. Installed packages whose publisher was revoked are surfaced as a **persistent ACP security alert**
   (name, reason, revoked_at) with a one-click **disable** using the existing H3 module kill-switch.
3. Policy config `novfora.registry.on_revoke`: `alert` (default — operator decides; auto-killing a live
   forum's module is itself a DoS vector) | `disable` (auto-kill-switch). Recorded in the ADR.
4. An operator-added key (manual trust, not registry-managed) is NEVER auto-disabled by the feed —
   the registry only manages its own rows. No privilege for the feed over local operator intent.

## 5. Threat model — criterion mapping

| Threat | Refusal mechanism | Structural? |
|---|---|---|
| Feed tampering | ed25519 over exact bytes vs pinned root key | ✔ |
| Mirror substitution (feed) | same signature; mirrors serve bytes, not trust | ✔ |
| Mirror substitution (zip) | per-version sha256 pinned inside the signed feed | ✔ |
| Feed rollback / freeze | monotonic `sequence` refusal + staleness warning | ✔ (freeze = detect) |
| Package downgrade | client refuses version < installed on the one-click path | ✔ |
| Unsigned/foreign zip | never listed; existing PackageSignature gate rejects regardless | ✔ |
| Revoked publisher | trust-key row disabled on refresh → install+upgrade dead | ✔ |
| Typosquatting | curated enrollment + vendor namespace (v1 posture) | human, recorded |
| Hostile zip content | UNTOUCHED ArchiveGuard (traversal/bomb/symlink/extension fences) | ✔ (existing) |

## 6. GO criteria — verdict

1. **Revocation reaches installed packages** — yes: the disabled trust key kills their upgrade path
   permanently; the alert + optional kill-switch handles running code. **MET.**
2. **Feed stays a static file** — yes: signed static JSON + sig; zero server-side execution;
   mirrorable; servable from any baseline host. **MET.**
3. **Client rides ArchiveGuard + PackageSignature untouched** — yes: the one-click path terminates in
   the existing `installFromZip`; the registry layer only fetches, verifies feed-level pins, and
   manages registry-owned trust-key rows via the existing `ModuleTrustKeys` service. **MET.**
4. **Downgrade and mirror-substitution structurally refused** — yes: sequence monotonicity (feed),
   version monotonicity (package), sha256 content addressing + root-key signature (mirrors). **MET.**

**GO.** Build scope for Registry v1 (NOV-125): the feed schema + verifier, the cron/manual refresh with
sequence + revocation processing, the ACP Browse + one-click install + security alert, the ops-side
`scripts/registry/build-feed.php` signing script, a test feed with ≥3 first-party packages (the Q&A
module, one theme, one plugin), and the full adversarial battery (hostile feed, wrong-key sig, rollback
sequence, tampered zip hash, revoked-key upgrade refusal). ADR-0113.
