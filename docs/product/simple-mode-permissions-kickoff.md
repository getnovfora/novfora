# Simple-mode permissions — layman capability toggles — Build Spec (design-first)

> Handoff spec. A **layman "simple mode"** for permissions: ~7 plain-language capability toggles per group
> that write the SAME `acl_entries` via the existing `GroupPermissionEditor` — a friendlier WRITE surface,
> **not an engine change**. **Design-first** (owner directive): the **capability→key mapping is the
> correctness-sensitive core** — a wrong mapping silently mis-grants — so this ships an **ADR FIRST**, and the
> mapping is treated as **apex-adjacent** (the permission editor is an apex-listed surface): verify the mapping
> + scope/conflict handling at the top rung (**ultracode — start Fable @ max**), and downgrade the toggle-UI
> scaffolding to Sonnet. Branch off `main`, gated + the mapping reviewed, git on the VPS.

## 1. Goal
Let a non-technical operator set what a group can do via a handful of plain-language switches instead of a
42-key Yes/No/Never grid — with an **"Advanced"** escape to the existing card editor. Same engine, same writes.

## 2. Scope / Non-goals
**In scope:** an ADR documenting the capability→key mapping; a `⚡group-simple-editor` SFC (per-group capability
toggles); a **Simple / Advanced switch** on the three group-permission homes (global / forum / club);
`setCapability()` writing the bundle via `GroupPermissionEditor::set()`; the scope + NEVER/trust-gate conflict
handling; i18n.
**Non-goals:** **no change to `GroupPermissionEditor` / the resolver / the catalog** (the write primitive is
reused as-is); no new `Role`/preset objects (simple mode is a UI write layer, not role machinery);
Administration-tier and the moderation cluster are **excluded** from simple mode (advanced-only); **never expose
`never`** in simple mode.

## 3. Locked constraints
Reuse `GroupPermissionEditor::set(group, key, scope, state)` — **toggle OFF = `'no'` (delete → inherit), NEVER
`'never'`**. Reuse the rank guard + escalation fence from the card editor (simple mode hides admin-tier anyway).
i18n under `admin.perms.*` (G8); the catalog `label`/`description` are already plain-language. Tests with the
feature; small conventional commits, `-s`, `Tommy Huynh <tommy@saturnhq.net>`; clean-room. Branch
`claude/simple-mode-perms` off `main`.

## 4. Step 0 — the ADR (do FIRST; this is the design-first gate, top rung)
Write a `DECISIONS.md` ADR (next free number) — **the capability→key mapping**, the correctness core. Start
from this proposed model (refine + justify each inclusion/exclusion):

| Capability toggle | Subtitle | Keys (bundle) |
|---|---|---|
| **Read & reply** | view forums and post replies | `forum.view`, `post.create`, `post.edit.own`, `post.delete.own` |
| **Start new topics** | open new threads | `topic.create` |
| **Post links & images** | hyperlinks + embedded images (trust-gated for new accounts) | `post.links`, `post.images`, `attachment.create` |
| **React & vote** | react to posts and vote in polls | `react.create`, `poll.vote` |
| **Create polls & tags** | attach polls; create/apply tags | `poll.create`, `tag.apply`, `tag.create` |
| **Follow members** | follow others — *global only* | `follow.create` (+ `follow.delete`) |
| **Send private messages** | *global only* | `pm.send` |

The ADR MUST pin:
1. **Each bundle + why** (and why these and not others).
2. **Mixed `scope_kind` handling** — a bundle with a `global` key (`tag.create`, `pm.send`, `follow.*`) shown on a **forum** surface: **only show a capability where every key in it is settable at the current scope** (recommended — skip global-only capabilities at forum scope), or write each key at its own catalog scope. Decide + document; the SFC must check `scope_kind` so it **never writes a silently-inert row**.
3. **The "toggle ON but a NEVER / trust-gate wins" case** (e.g. `post.links` set Yes on a TL0 group with a seeded NEVER): the simple view must **not lie** — surface a small "restricted by trust level / a Never rule" note next to a green toggle that resolves denied.
4. **Exclusions** — all Administration-tier keys, `bans.manage`, the moderation cluster (`post.edit.any`/`post.delete.any`/`post.history.view`/`topic.moderate`), `club.manage` (advanced-only) — and why **"Moderate" is deliberately not a simple toggle** (silently granting `bans.manage`/`post.edit.any` is the exact mis-grant we're avoiding).

## 5. Sequence
1. **ADR (step 0)** — the mapping, reviewed. **Top rung** (the correctness core; the rest depends on it).
2. **`⚡group-simple-editor` SFC** (mapping/scope logic at xhigh; the toggle Blade scaffolding Sonnet) — per group, a toggle per capability *applicable at the current scope*; `setCapability(groupId, capability, enabled)` loops `GroupPermissionEditor::set($group, $key, $scope, $enabled ? 'yes' : 'no', audit:false)` over the bundle in one transaction + one audit entry. A capability reads "on" **iff every** key in it is ALLOW at this scope. Reuse the rank guard + the editor's authorize path. Render the trust/NEVER conflict note.
3. **Simple / Advanced switch** on the three homes (`admin/group-permissions.blade.php`, `admin/forum-permissions.blade.php`, `clubs/edit.blade.php`) — conditionally embed `⚡group-simple-editor` vs the existing `⚡group-editor` (query-param or Alpine toggle); default Simple, remember the choice.
4. **i18n** — `admin.perms.mode_simple`/`mode_advanced` + `admin.perms.capabilities.*` labels/subtitles.
5. **Gates.**

## 6. Correctness seams (apex-adjacent — the review pins)
- **The mapping is the load-bearing element** — test each capability: ON writes EXACTLY its keys as ALLOW at the right scope; OFF deletes them (→ inherit) and **never writes `never`**; an Administration/moderation key is **never** writable via simple mode; a mixed-scope bundle behaves per the ADR (zero silently-inert writes).
- **Faithful display** — a capability shows "on" only when all its keys resolve ALLOW for that group at that scope; the trust/NEVER conflict surfaces a note (same "don't lie to the operator" bar as the inspector readability — a green toggle that's actually denied is a trust bug).
- **No engine change** — `GroupPermissionEditor`/resolver/catalog untouched; simple mode only batches existing `set()` calls behind one transaction + audit entry.

## 7. Verification / done
Gates green; the ADR records the mapping; each capability round-trips (ON → its keys ALLOW; OFF → inherit;
never `never`); admin-tier/moderation excluded; mixed-scope + trust-conflict handled per the ADR; the
Simple/Advanced switch works on all three homes; **the advanced card editor still works unchanged**. PR to
`main` with the capability mapping flagged for owner review.

## 8. Commit
Branch `claude/simple-mode-perms` off `main`; small conventional commits (**ADR first**); `-s`,
`Tommy Huynh <tommy@saturnhq.net>`. PR to `main`.

Read docs/product/simple-mode-permissions-kickoff.md and execute it.
