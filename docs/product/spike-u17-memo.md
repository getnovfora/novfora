# Spike memo — U17 · Plugin install-from-zip + signature/trust gate (`NOV-115`, ◆ APEX)

> GO/NO-GO memo per `docs/product/FABLE-U7-U17-KICKOFF.md` Phase 2. Written 2026-07-01 before any U17
> code. Decision at the bottom. Product surface per `feature-list.md` (U17) and `reevaluation-synthesis.md`
> §Tier 4 ("install-from-zip + signature/trust gate; PARTIAL — enable/disable + trust guardrails; no zip
> install; APEX: untrusted package"). This closes the ADR-0031 / H3 flag that a package **signature** and
> a distribution channel were "documented future enhancement, not built".

## 1. What ships

A new **install-from-zip** seam in the ACP Modules page that stages an uploaded `.zip` into
`modules/<vendor>/<name>/` and then calls the **existing** `ModuleManager::install($slug)`. The lifecycle
after staging is unchanged — this feature is entirely about getting bytes safely onto disk under a slug an
operator can trust. Two orthogonal gates:

1. **Archive-safety gate** (hostile-zip hardening) — every entry is validated before a single byte is
   written: path within the target, no traversal, no symlink, entry-count cap, per-file and total
   uncompressed-size caps, compression-ratio cap (zip-bomb), extension allowlist.
2. **Signature / trust gate** — a detached **ed25519** signature (`module.sig`) over the zip's canonical
   content, verified against a configured **trusted-key registry** before install. Trust tiers:
   - `signed` — verifies against a trusted key → installs (still disabled; enable still needs the H3
     full-trust consent).
   - `unsigned` / `bad-signature` / `untrusted-key` — **rejected by default**; the archive is quarantined
     (moved to a quarantine dir, never staged into `modules/`) and audited. An operator may set a policy
     `allow_unsigned` (dev only, env-gated, loud) to permit unsigned installs — off by default.

## 2. Threat model (the APEX part)

| Vector | Position |
|---|---|
| **Zip-slip / path traversal** (`../../etc/cron.d/x`, absolute paths, Windows `..\\`, drive letters) | Never call `ZipArchive::extractTo()` on an untrusted archive (that's what `RestoreService` does — safe only because it extracts *self-produced* backups). Instead iterate entries, and for EACH: reject any name containing `..` segments, a leading `/` or `\`, a drive letter, or a null byte; compute the real target path and assert `str_starts_with(realpath-normalized target, staging root . '/')` before writing. |
| **Symlink escape** | `ZipArchive` can carry symlink entries (unix mode bits in external attributes). Detect the symlink mode on each entry and reject the archive — we never create a symlink from an untrusted package. |
| **Zip bomb** (43 KB → many GB; nested/quine) | Cap (a) entry count, (b) each entry's uncompressed size, (c) total uncompressed size, (d) per-entry compression ratio (`uncompressed/compressed`). All read from the central directory (`statIndex`) BEFORE extracting, and re-checked with a bounded streamed copy (never `extractTo` the whole thing) so a lying header can't slip a bomb through. |
| **Malicious payload once on disk** | The package is code — enabling it is full-trust by design (H3). U17 does NOT sandbox execution (out of scope, unchanged from H3); it ensures the operator installs a package whose **authenticity + integrity** they can verify (signature) and that **enable** still requires the explicit H3 consent. Staging installs DISABLED. |
| **Signature forgery / tamper** | ed25519 detached sig over a canonical byte range (the raw zip bytes minus the sig entry, or a separate uploaded `.sig`), verified with `sodium_crypto_sign_verify_detached` against a trusted public key (constant-time inside libsodium). A tampered zip → different bytes → verify fails → reject. No key present / key not in the trusted registry → reject. |
| **Trust-key management** | Trusted ed25519 public keys live in a small `module_trust_keys` table (name + base64 public key + enabled), admin-managed in the ACP, audited. A signature verifies if ANY enabled trusted key validates it. Removing/disabling a key immediately stops trusting packages it signed (future installs; already-installed modules keep their integrity hash). |
| **Slug spoofing / overwrite** | The slug comes from the validated `module.json` inside the archive (via the existing `ManifestValidator::assertSlug` — the path-safe `vendor/name` boundary guard) — NOT from the filename. Installing over an existing module dir is refused unless it's a genuine upgrade path (operator-confirmed); the staging dir is a fresh temp, atomically moved into place only after ALL gates pass. |
| **Upload abuse / DoS** | Admins-only surface (admin.access + staff-2FA), so the attacker is already an admin — but still: a hard upload-size cap (php + app-level), the bomb caps above, and the whole operation runs synchronously within one request bounded by the caps (no queue needed — Baseline-safe). |
| **Partial-install / crash mid-extract** | Extraction is to a **temp staging dir**; `modules/<vendor>/<name>/` is only populated by an atomic `rename()` after every gate passes. A crash leaves the temp dir (cron/GC prunes it), never a half-written live module. Reversible: if `install()` fails, the staged dir is removed and the archive quarantined. |

## 3. Reversible / idempotent install + rollback

- Staging: extract to `storage/app/module-staging/<random>/`. Validate manifest + gates. On any failure →
  delete staging, move the original archive to `storage/app/module-quarantine/<random>.zip`, audit
  `module.zip_install.rejected` with the reason, surface an inline error (never a 500).
- Commit: atomic `rename(staging, modules/<slug>)`; then `ModuleManager::install($slug)` (records the
  package_hash baseline, disabled). If `install()` itself throws, roll back the rename (remove the moved
  dir) and quarantine. Audit `module.zip_install.accepted`.
- Idempotent: re-uploading the same signed package resolves to the same slug; installing over an existing
  dir is an explicit **upgrade** confirmation (reuses `ModuleManager::upgrade`, which already refuses a
  version downgrade), not a silent clobber.

## 4. Baseline-tier viability

- `ext-zip` and `ext-sodium` are **both bundled and enabled by default in PHP 8.3** (the floor) — verified
  present in the gate container. ed25519 verification needs no Composer package. Degrade: if `ext-sodium`
  is somehow absent, the signature gate fails **closed** (every package reads as unsigned → rejected under
  the default policy), which is the safe direction.
- Synchronous, no daemon/queue/Redis: extraction + verification happen in the request, bounded by the size
  caps. No external service. Quarantine + staging are plain filesystem dirs under `storage/`.
- Subdirectory installs unaffected (filesystem paths via `storage_path()`/`config`).

## 5. Reuse map (verified in-code)

- `ManifestValidator::assertSlug()` / `fromDirectory()` — the path-safe slug boundary + fail-closed manifest
  parse; the staged package's slug comes from here, never the upload filename.
- `ModuleManager::install()` / `upgrade()` / `packageHash()` — the post-staging lifecycle is unchanged; the
  integrity hash blesses the just-installed files.
- `App\Support\Audit::log()` — every transition (`module.zip_install.accepted` / `.rejected`,
  `module.trust_key.added` / `.removed`).
- The `RestoreService::validate()` "streamed-hash-then-verify-then-act" idiom is the closest existing
  pattern — but its `unzip()` uses `extractTo` on a TRUSTED archive; U17 must NOT reuse `extractTo` for the
  hostile case. All hostile-zip hardening (traversal/symlink/bomb caps) is **new** — no precedent exists.
- HMAC precedent (`WebhookVerifier`, `hash_equals`) informs the "verify before touching anything, constant
  time, fail closed" discipline, but the crypto itself (asymmetric ed25519) is new usage.

## 6. Out of scope (v1)

- A real PHP execution sandbox (unchanged from H3 — full-trust-on-enable is the documented model).
- A remote marketplace / auto-update-over-network (this is upload-a-file, not fetch-from-URL — no SSRF
  surface introduced). The signature format + trust registry are the seam a future marketplace would reuse.
- Signing tooling for authors beyond a documented `sodium_crypto_sign` recipe + a CLI helper.

## 7. Acceptance mapping (kickoff → deliverable)

- Zip hardened against slip/symlink/bomb → §2 + `PluginZipInstallSecurityTest` adversarial fixtures.
- Signature verify + trust tiers, reject unsigned/tampered by default; key management documented → §1/§2 +
  `module_trust_keys` + ACP.
- Reversible/idempotent install with rollback, quarantine, audit trail → §3.
- Respect the semver'd module API contract; Baseline-safe → §4/§5.
- ADR in `DECISIONS.md` (0104) + adversarial verify-then-refute review before merge.

## 8. Decision

**GO.** The untrusted-archive boundary decomposes into (a) entry-by-entry validation with never-extractTo
+ hard caps (new, but well-understood), (b) ed25519 detached-signature verification against an
admin-managed trusted-key registry, fail-closed, using bundled `ext-sodium`, and (c) atomic
stage-then-rename that keeps `modules/` consistent and reuses the proven `ModuleManager` lifecycle. All
Baseline-native, no new dependency. The one genuinely new-crypto surface (ed25519 verify) is a single
libsodium call over a canonical byte range; the adversarial fixtures (traversal, symlink, bomb, bad sig,
untrusted key, truncated archive) are the acceptance gate.
