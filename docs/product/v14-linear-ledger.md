<!-- SPDX-License-Identifier: Apache-2.0 -->

# v1.4 Linear ledger — BLOCKED WRITES (apply by hand)

> **Linear MCP is completely absent in this build environment** — every tracker write was blocked. This is the
> full ledger for the v1.4 build cycle: apply these state flips + completion comments + new issues by hand.
> Convention in play: **Done on merge** (the slices are on branches, not yet merged to `main`; see the release
> decision in `PROJECT-STATE.md` ☀️). This supersedes the partial ledger on `claude/v14-morning-report`.

## Issues touched — flip state + post the completion comment

| Issue | Slice | Branch · head | ADR | Review outcome | → State |
|---|---|---|---|---|---|
| NOV-112 | U14 pending-member exit-ramp | `claude/v14-u14-registration` · `1d233a8` | 0119 | (standard) | Done on merge |
| NOV-111 | U13 CIDR/range IP-ban (elevated) | `claude/v14-u13-ip-bans` · `8b20845` | 0121 | apex: 1 HIGH + 3 MED fixed | Done on merge |
| NOV-114 | U16 maintenance/logs/mail-test | `claude/v14-u16-maintenance` · `5337af3` | — | focused: 2 HIGH + 5 MED fixed | Done on merge |
| NOV-116 | U19 topic fields + move-redirect | `claude/v14-u19-topic-fields` · `d621cc6` | 0122 | focused: 0 HIGH/MED (LOWs fixed) | Done on merge |
| NOV-127 | staff workflow + Hearth metrics | `claude/v14-staff-workflow` · `69154c3` | 0114 | focused: 2 HIGH + 2 MED fixed | Done on merge |
| NOV-135 | E1 scoped Admin-API tokens | `claude/v14-e1-api-tokens` · `783a6bb` | 0115 | apex: 4 MED fixed | Done on merge |

Per-issue completion comment (paste on each): branch + head SHA above; gate = full suite green + Pint + Larastan
clean + migrate round-trip; the ADR number; and the confirmed apex/focused findings (see each ADR's review note).
Earlier-session slices already on branches: NOV-124 Registry (GO, `94f48bd`, ADR-0113), U11 template hooks
(`b193a08`, ADR-0112), U9/U10/U12/importers.

## 4E remainder — NOT built (keep OPEN / re-scope)

| Issue | Slice | State |
|---|---|---|
| NOV-136 | E2 read surface | Open — planned in `plan-4e-admin-api-and-upgrade.md` |
| NOV-137 | E3 write surface ◆ | Open |
| NOV-138 | E4 backups ◆ (ADR-0116) | Open |
| NOV-139 | E5 restore ◆◆ (ADR-0117) | Open — dedicated cycle |
| NOV-141 | E7 self-upgrade ◆◆ (ADR-0118) | Open — dedicated cycle |
| NOV-142 | E8 docs | Open |

## New issues to FILE (discoveries this cycle — LOW / non-blocking)

1. **U13** — per-*request* IP-ban enforcement middleware (today: registration boundary only); narrow the
   cache-outage fail-open.
2. **U16** — index `posts.ip_address` / `sessions.ip_address` for the IP-investigation lookup (unindexed scans;
   admin-gated, self-inflicted).
3. **U19** — a per-topic field-value **edit** surface (no topic-edit page exists today); clean up an orphaned
   "moved" shadow when its target topic is deleted.
4. **staff/Hearth** — a per-moderator staff-load breakdown; a true signup-cohort retention curve (needs a per-day
   activity-history table) — both deferred from Hearth v1.
5. **E1** — extend the token `ip_allowlist` to CIDR once U13's `CidrMatcher` is on `main` (E1 ships exact-IP);
   widen the `isStaff()`-based 2FA self-guard in the group-editor SFCs like E1's mint gate (pre-existing pattern).

## Owner product decisions (parked — do not guess)

- **Self-upgrade auto-apply default** (E7): `off / security-only / all-patch` — spec recommends security-only,
  chosen at install.
- Carried: **U8** imported-username revert (ADR-0106) · **U18** Turnstile fail-open (ADR-0107).
