<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# RH-11 — No-SSH restore: the Backups panel can't restore — Claude Code kickoff

> **Finding (RH-11):** `hearth:restore` exists only as a CLI command; the Admin → System → Backups panel
> can create/download but not restore. A no-SSH operator therefore has **no recovery path at all** — and
> the RH-10 recovery docs already (incorrectly) tell them to "restore the pre-upgrade backup via the admin
> Backups panel." Documented-but-unimplemented, same class as RH-10. **Beta gate:** invites wait on this.

---

```
Add a safe, no-SSH restore to the Admin → System → Backups panel, reusing the RH-10 upgrade machinery
(maintenance gate, audit, health surfacing). Branch + PR. No other features.

STEP 0: read PROJECT-STATE.md, app/Console/Commands/RestoreCommand.php (the existing restore pipeline),
app/Upgrade/* (the RH-10 gate/lock patterns), the ⚡backups SFC. Branch from main (includes RH-10).
Commit identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Log RH-11
in real-host-findings.md.

BUILD:
1) Extract the restore pipeline out of the CLI command into a service (CLI + panel share ONE path),
   if not already shaped that way.
2) Panel action: each backup row gains Restore — admin.access + 2FA (self-guarded in the SFC, per the
   RH-10 panel pattern) + a typed confirmation (the backup's name) + an explicit "this overwrites the
   current database and files" warning showing the backup's date/size.
3) Safety sequence (mirror RH-10's choreography): validate the archive (existing integrity manifest) →
   enter the maintenance gate → restore DB + files → flush caches + refresh SchemaState (a restored DB
   may have an older schema — the RH-10 tick must then see pending migrations and handle them; get this
   interaction RIGHT and test it) → exit maintenance → audit-log (who, which backup, duration, result).
4) Failure path: validation failure → refuse before touching anything; mid-restore failure → stay in
   maintenance with the honest operator hint; /health surfaces the restore window + a stuck state.
5) The restore must run within shared-host limits: if the request-time budget is a risk for large
   archives, run it as a queued job drained by cron (panel shows progress/status) — your call, justify.
6) Docs: getting-started + REAL-HOST-VALIDATION restore sections updated to match reality; the RH-10
   recovery references become true. FLAG as follow-up (do not build): restore-from-UPLOADED archive
   (operator's off-host copy) — note it in the findings entry.

TESTS: panel authz (non-admin/non-2FA refused) · typed-confirm required · happy-path round-trip in the
sandbox (create → mutate → restore → mutation gone), mirroring BackupRestoreTest · the restore→pending-
migrations interaction (restore an older-schema backup → RH-10 detects + upgrades cleanly) · validation
refusal on a corrupt archive · maintenance entered/exited · audit entry · /health during the window.

DELIVER: branch + PR; suite + all gates green; rebuild scripts/build-release.sh + verify-release.sh and
report bundle size + sha256 (next live deploy). Update PROJECT-STATE + findings (RH-11 → FIXED).

SCOPE FENCE: panel restore + its safety rails + docs only. No upload-restore, no ACP-v1 scope creep.
```

---

## Live rehearsal (owner + Cowork — this validates RH-11 and closes the last beta gate)
1. Deploy the resulting bundle (RH-10 self-migrates as before).
2. Admin → Backups → **Create backup**; **Download** it (off-host copy — do this habitually).
3. Make a marker change (one test post).
4. **Restore** that backup from the panel → maintenance blip → marker post is gone → site healthy,
   `/health` green, audit shows the restore.
