
# NovFora UI/UX + Engineering Handoff for Claude Code

**Prepared for:** NovFora frontend/admin enhancement work  
**Primary repo:** https://github.com/getnovfora/novfora  
**Live community:** https://novfora.com/community  
**Goal:** Give Claude Code, Cursor, Codex, Antigravity, and Manus repo-aware prompts that improve NovFora's public community and admin UI/UX while preserving backend correctness.

## Core direction

NovFora should feel like:

> Warm indie web + premium SaaS clarity + classic forum density.

Avoid:

- Generic Bootstrap/Laravel starter-kit visuals
- Old phpBB/MyBB/SMF styling
- A Discourse clone
- One-off redesigns that bypass the existing component system
- Fake metrics, fake permissions, or invented backend state

## Tool responsibility map

| Tool | Primary role |
|---|---|
| Claude Code | Architecture, repo-aware planning, permission contracts, admin system design, backend-aligned UX |
| Cursor | Hands-on Blade/Tailwind/Livewire polish, component refinement, UI implementation |
| Codex | PR review, tests, accessibility/security checks, UI audit fix batches |
| Antigravity | Isolated UI prototypes and browser QA passes |
| Manus | Competitor UX research, product positioning, UX copy, microcopy |

## Recommended execution order

1. Claude Code: frontend/product architecture plan
2. Codex: run `UI-AUDIT-FIX-SPEC.md` fix batch
3. Cursor: layout/nav/search polish
4. Cursor: forum index polish
5. Cursor: topic list polish
6. Cursor: thread view polish
7. Claude Code: permission-aware UI contract
8. Codex: permission/accessibility PR review
9. Claude Code: admin dashboard/ACP polish plan
10. Cursor: admin dashboard MVP polish
11. Antigravity: isolated visual prototypes
12. Manus: competitor UX research and microcopy

## Important working rule

Do not let any tool redesign the UI in isolation. The frontend should always respect the backend authorization model, real data availability, theme extension points, accessibility, shared-host compatibility, and the current Blade/Livewire/Tailwind architecture.


## Shared context to paste into every tool

```text
You are working on NovFora, an open-source, self-hosted forum/community platform.

Before making recommendations or code changes, read these files:
- CLAUDE.md
- ARCHITECTURE.md
- PROJECT-STATE.md
- UI-AUDIT-FIX-SPEC.md
- CONTRIBUTING.md
- README.md

Current stack:
- Laravel 13
- PHP 8.3+
- Livewire 4
- Alpine
- Blade
- Tailwind 4
- Vite
- TipTap-class WYSIWYG editor
- Server-rendered, SEO-safe frontend
- Baseline shared-host tier plus enhanced Redis/Meilisearch/Reverb/S3 tier

Important frontend files and folders:
- resources/views/layouts/app.blade.php
- resources/views/layouts/auth.blade.php
- resources/views/forum/index.blade.php
- resources/views/forum/show.blade.php
- resources/views/forum/topic.blade.php
- resources/views/forum/partials/
- resources/views/components/ui/
- resources/views/components/forum/
- resources/views/components/admin/
- resources/css/app.css
- resources/js/

Important backend areas that affect UI:
- routes/web.php
- app/Permissions/
- app/Policies/
- app/Forum/
- app/Community/
- app/Moderation/
- app/Admin/
- app/Theme/
- app/Settings/

Product direction:
NovFora should feel like warm indie web + premium SaaS clarity + classic forum density.
Do not make it look like generic Bootstrap, old phpBB/MyBB/SMF, or a Discourse clone.

Hard rules:
- Inspect current implementation before changing anything.
- Reuse existing x-ui components where possible.
- Do not invent routes, models, policies, or backend state if equivalents already exist.
- Do not fake metrics or permissions.
- Do not introduce new frontend packages unless clearly justified.
- Preserve shared-host baseline compatibility.
- Keep changes incremental and reviewable.
- For UI/CSS/template changes, run or account for npm build and public/build changes.
- Add/update tests where behavior changes.
- Respect clean-room project rules.
```


## Claude Code prompt: frontend/product architecture lead

```text
Act as NovFora's lead frontend/product architect.

Goal:
Create a concrete, repo-aware plan to greatly improve NovFora's public forum UI/UX and admin experience without destabilizing the backend.

First, read:
- CLAUDE.md
- ARCHITECTURE.md
- PROJECT-STATE.md
- UI-AUDIT-FIX-SPEC.md
- CONTRIBUTING.md

Then inspect:
- resources/views/layouts/app.blade.php
- resources/views/layouts/auth.blade.php
- resources/views/forum/index.blade.php
- resources/views/forum/show.blade.php
- resources/views/forum/topic.blade.php
- resources/views/forum/partials/
- resources/views/components/ui/
- resources/views/components/forum/
- resources/views/components/admin/
- resources/css/app.css
- routes/web.php
- app/Permissions/
- app/Policies/
- app/Theme/
- app/Community/
- app/Admin/

Do not start coding yet.

Deliver a strong implementation plan with:

1. Current frontend architecture summary
2. Existing UI component inventory
3. What is already good and should be preserved
4. Public forum UX problems
5. Admin UX problems
6. Accessibility risks
7. Mobile/responsive risks
8. Permission-aware UI gaps
9. Theme/customization opportunities
10. Areas where UI-AUDIT-FIX-SPEC.md already gives direction
11. Proposed branch/PR sequence
12. Files likely touched per PR
13. Tests needed per PR
14. Risks and rollback strategy

Prioritize:
- reducing duplicate/noisy responsive markup
- improving navigation/search
- polishing forum index
- polishing topic list
- polishing thread view
- strengthening signed-out states
- preventing avoidable 403s
- improving admin dashboard clarity
- improving moderation/admin task flows
- preserving backend correctness

Be specific. Reference actual files, components, routes, and classes.
```


## Claude Code prompt: permission-aware UI contract

```text
Act as a senior Laravel authorization and UX engineer for NovFora.

Goal:
Design and implement a permission-aware UI contract so users only see actions that are valid, explainable, or intentionally sign-in gated.

First read:
- CLAUDE.md
- ARCHITECTURE.md
- PROJECT-STATE.md
- UI-AUDIT-FIX-SPEC.md

Inspect:
- app/Permissions/
- app/Policies/
- routes/web.php
- resources/views/forum/index.blade.php
- resources/views/forum/show.blade.php
- resources/views/forum/topic.blade.php
- resources/views/components/forum/
- resources/views/components/ui/
- resources/views/layouts/app.blade.php
- moderation/admin views that expose actions

Map every major user-facing action:
- view forum
- create topic
- reply
- edit own post
- delete own post
- report post
- quote/reply
- bookmark
- follow/subscribe
- lock topic
- pin topic
- move topic
- merge topic
- moderate forum
- send private message
- access clubs/groups
- access admin panel
- manage users/forums/permissions/themes/plugins

For each action, decide the UI rule:
- show enabled
- show disabled with explanation
- show sign-in CTA
- hide entirely
- allow but handle failure with friendly 403

Deliver:
1. Current permission model summary
2. Action-to-policy/canDo matrix
3. Recommended helper/component API for views
4. Blade examples using existing patterns
5. Backend data needed by templates
6. Friendly 403/error-state copy
7. Pest/Dusk test plan
8. First implementation PR

If implementation is safe, implement the first PR:
- choose one vertical slice, preferably forum/topic/post actions
- add or update tests
- avoid broad rewrites
```


## Claude Code prompt: admin dashboard and ACP polish

```text
Act as NovFora's admin UX architect.

Goal:
Improve the admin control panel experience while preserving NovFora's current ACP v3 work.

First read:
- PROJECT-STATE.md
- UI-AUDIT-FIX-SPEC.md
- ARCHITECTURE.md
- CLAUDE.md

Inspect:
- resources/views/admin/
- resources/views/components/admin/
- app/Admin/
- app/Permissions/
- app/Moderation/
- app/Theme/
- routes/web.php
- tests covering admin behavior

Deliver a concrete plan for admin UX polish:
1. Current admin IA summary
2. Existing admin components and shell
3. What ACP v3 already provides
4. Dashboard widgets that can use real existing data
5. Moderation workflow improvements
6. Permission management UX improvements
7. Theme/plugin/settings UX improvements
8. System health/update safety opportunities
9. Missing backend data, if any
10. First three safe PRs
11. Tests needed for each PR

Do not suggest fake metrics.
Do not replace the admin architecture.
Prefer improving the existing admin shell and components.
```


## Claude Code prompt: Permission Inspector signature feature

```text
Design the NovFora Permission Inspector as a signature admin feature.

Goal:
Allow an admin to answer:
"Why can or can't this user perform this action?"

First inspect:
- app/Permissions/
- app/Policies/
- database migrations related to ACL/permissions/groups/roles
- admin permission views
- forum/topic/post policies
- existing tests for permissions

Actions to support in MVP:
- view forum
- create topic
- reply
- edit own post
- delete own post
- send private message
- lock topic
- move topic
- moderate forum
- access admin panel

Inspector should explain:
- final allow/deny result
- global permission source
- forum-specific permission source
- group/role/rank source
- explicit NEVER/deny source if applicable
- inherited permission chain
- suggested fix

Deliver:
1. Current permission system summary
2. MVP data/service design
3. Admin UI design
4. Example explanations
5. Routes/controllers/views/components needed
6. Tests
7. First implementation branch scope

If implementation is safe, build only the service layer and one admin MVP screen first.
```


## Cursor prompt: public layout, navigation, and search polish

```text
Improve NovFora's public layout, navigation, and search UX.

Before editing, inspect:
- resources/views/layouts/app.blade.php
- resources/views/components/ui/
- resources/css/app.css
- routes/web.php
- any search-related views/components

Goals:
- Make the header feel premium, simple, and intentional.
- Reduce repeated navigation/search links.
- Preserve existing theme regions, skip link, PWA metadata, appearance/density controls, auth/user menu, and language switcher.
- Improve mobile menu behavior.
- Make search easier to find and use.
- Avoid duplicate screen-reader content.
- Keep the warm indie-web + premium SaaS direction.

Specific tasks:
1. Audit the current desktop and mobile nav.
2. Remove or consolidate duplicated links.
3. Improve active states.
4. Improve search trigger/field accessibility.
5. Ensure mobile portrait does not overflow.
6. Preserve existing route names and auth behavior.
7. Reuse existing x-ui components where appropriate.
8. Do not introduce a new JS framework or dependency.

After changes:
- summarize files changed
- explain behavior preserved
- list any browser testing needed
- run/build assets if applicable
```


## Cursor prompt: forum index enhancement

```text
Improve the NovFora forum index experience.

Focus files:
- resources/views/forum/index.blade.php
- resources/views/forum/partials/forum-row.blade.php
- resources/views/forum/partials/info-center.blade.php
- resources/views/components/ui/
- resources/views/components/forum/
- resources/css/app.css
- app/Community/ if activity feed changes are needed

Goals:
- Make /community feel more welcoming and premium.
- Keep classic forum density, but improve visual hierarchy.
- Reduce repeated/noisy forum metadata.
- Improve latest activity readability.
- Improve mobile layout.
- Preserve permission checks and existing forum tree behavior.
- Preserve theme regions and sandbox/template hooks.

Specific improvements:
1. Add or refine a "Start here" / welcome panel using existing layout patterns.
2. Improve board/forum rows:
   - clearer icon/title/description
   - cleaner topic/post counts
   - clearer latest activity
   - unread/new state if already available
3. Avoid duplicated desktop/mobile content where one responsive component can work.
4. Where duplicated markup remains necessary, ensure assistive tech does not read duplicates.
5. Improve recent activity presentation without faking data.
6. Preserve viewer->canDo('forum.view', ...) style permission filtering.
7. Use existing x-ui card, badge, empty, container, avatar, and related components.

Do not:
- rewrite the whole page from scratch
- invent backend data
- remove theme extension points
- remove accessibility affordances

Deliver:
- implemented improvement
- summary of changed files
- list of follow-up backend data that would further improve the UI
```


## Cursor prompt: topic list polish

```text
Improve NovFora forum/topic list pages.

Focus files:
- resources/views/forum/show.blade.php
- resources/views/forum/partials/
- resources/views/components/forum/prefix-badge.blade.php
- resources/views/components/forum/tag-chip.blade.php
- resources/views/components/ui/
- resources/css/app.css
- routes/web.php only if needed

Goals:
- Make topic lists easier to scan.
- Improve filters, labels, prefixes, pinned/locked/unread states.
- Preserve current permission logic.
- Preserve existing board list styles.
- Improve mobile tap targets and readability.
- Avoid generic SaaS table styling.

Specific tasks:
1. Audit existing table and stacked mobile topic layouts.
2. Improve filter UI into polished pill/tab treatment where appropriate.
3. Improve topic rows:
   - title
   - excerpt if present
   - author
   - latest activity
   - replies/views
   - tags/prefix
   - pinned/locked/unread state
4. Make "New Topic" visually clear but not overpowering.
5. Improve empty states for forums with no topics or filtered results.
6. Ensure permission-aware create/moderation actions remain correct.
7. Reuse existing x-ui components.
8. Avoid duplicating semantic content for desktop/mobile.

Deliver:
- code changes
- short explanation of UX improvements
- any needed tests or browser checks
```


## Cursor prompt: thread/topic view polish

```text
Improve NovFora's thread/topic reading experience.

Focus files:
- resources/views/forum/topic.blade.php
- resources/views/components/forum/
- resources/views/components/ui/
- resources/css/app.css
- app/Forum/ and policies only if necessary

Goals:
- Make reading discussions feel polished, warm, and comfortable.
- Improve post cards.
- Improve author metadata display.
- Improve action clarity.
- Improve signed-out reply CTA.
- Preserve moderation, bookmark, follow, quote, report, edit, delete, history, and reply composer functionality.
- Preserve existing poster-position settings if present.

Specific tasks:
1. Audit the existing topic header, breadcrumb, tag/status area, post cards, related topics, and reply area.
2. Improve post card layout:
   - avatar
   - username/display name
   - staff flair/role if present
   - timestamp
   - body typography
   - action row
3. Improve topic title and metadata hierarchy.
4. Improve signed-out CTA copy and placement.
5. Improve locked-topic state.
6. Improve related topics UI.
7. Ensure action visibility matches permission checks.
8. Ensure mobile reading experience is strong.
9. Reuse existing components and CSS tokens.

Avoid:
- deleting useful classic-forum metadata
- hiding moderation functions
- faking statuses
- introducing fragile one-off CSS

Deliver:
- code changes
- UX summary
- permission/a11y concerns found
- tests or manual browser checks needed
```


## Cursor prompt: auth/register polish

```text
Polish NovFora's auth experience.

Focus files:
- resources/views/layouts/auth.blade.php
- resources/views/auth/
- resources/views/components/ui/field.blade.php
- resources/views/components/ui/input.blade.php
- resources/views/components/ui/button.blade.php
- resources/css/app.css
- validation/auth-related classes only if needed

Goals:
- Make login/register feel trustworthy and polished.
- Improve form hierarchy, helper text, errors, and focus states.
- Improve anti-spam/honeypot presentation without weakening security.
- Preserve Laravel/Fortify behavior.
- Improve mobile usability.

Specific tasks:
1. Audit register/login/password reset views.
2. Improve layout and visual hierarchy.
3. Improve field labels, helper text, validation errors, and focus states.
4. Ensure honeypot fields are hidden correctly from visual users and assistive tech.
5. Make anti-spam question copy friendlier and configurable if already supported.
6. Improve "Already have an account?" and "Need an account?" transitions.
7. Do not reduce security or validation.
8. Reuse x-ui form components.

Deliver:
- code changes
- security notes
- a11y notes
- testing checklist
```


## Cursor prompt: existing component system refinement

```text
Refine NovFora's existing UI component system.

Focus:
- resources/views/components/ui/
- resources/views/components/forum/
- resources/views/components/admin/
- resources/css/app.css
- frontend views that duplicate component-like markup

Goal:
Improve consistency and reuse without over-abstracting.

Tasks:
1. Inventory existing UI components.
2. Identify repeated markup in forum index, forum show, topic view, auth views, and admin views.
3. Improve only the components that are already used or clearly reusable:
   - card
   - button
   - badge
   - tabs
   - empty
   - field/input/select/textarea
   - table
   - avatar
   - dropdown
   - breadcrumbs
4. Replace repeated markup in one or two high-value views.
5. Keep changes incremental.
6. Preserve current props/API where possible.
7. Avoid a huge breaking refactor.

Design direction:
Warm indie web + premium SaaS clarity + classic forum density.

Deliver:
- component improvements
- views updated to use them
- notes on future component migrations
```


## Cursor prompt: admin dashboard MVP polish

```text
Polish the NovFora admin dashboard MVP using the existing admin shell and components.

Before editing, inspect:
- resources/views/admin/
- resources/views/components/admin/
- app/Admin/
- app/Moderation/
- app/Permissions/
- app/Theme/
- routes/web.php

Goals:
- Make the admin homepage immediately useful.
- Surface real community/admin health signals.
- Improve visual hierarchy.
- Keep it approachable for non-technical forum owners.
- Do not fake unavailable metrics.

Dashboard sections to improve if real data exists:
- community overview
- new members
- new topics/posts
- reports/moderation queue
- pending approvals
- spam/anti-spam signals
- recent admin actions
- version/update status
- theme/plugin status
- system health

Rules:
- Reuse existing x-admin and x-ui components.
- Preserve admin authorization.
- Preserve existing routes and policies.
- Use placeholder/empty states only when honest.
- Make mobile admin usable.
- Avoid big architecture changes unless already planned.

Deliver:
- changed files
- screenshots or manual browser checklist if available
- missing backend data
- tests needed
```


## Codex prompt: run the existing UI audit spec

```text
Use UI-AUDIT-FIX-SPEC.md as the source of truth for a focused NovFora UI/UX fix batch.

First read:
- CLAUDE.md
- CONTRIBUTING.md
- PROJECT-STATE.md
- UI-AUDIT-FIX-SPEC.md

Goal:
Implement the next safest, highest-priority UI audit fixes as small reviewable changes.

Rules:
- Do not mix unrelated fixes into one large PR.
- Follow the sequencing in UI-AUDIT-FIX-SPEC.md where still applicable.
- Check whether each issue is already fixed before editing.
- Prefer one logical branch/PR per issue or tightly related group.
- Add/update tests.
- Run relevant gates or document what could not be run:
  - composer pint
  - phpstan/larastan if configured
  - pest
  - dusk where UI/browser behavior is involved
  - npm build for UI/CSS/template changes
- Ensure public/build changes are handled according to CONTRIBUTING.md.
- Preserve baseline shared-host compatibility.
- Do not add new dependencies unless absolutely necessary.

Deliver:
1. Issues selected
2. Current status of each issue
3. Files changed
4. Tests added/updated
5. Gates run
6. Remaining risks
```


## Codex prompt: PR review for UI/UX regressions

```text
Review this NovFora UI/UX PR as a strict senior reviewer.

Read first:
- CLAUDE.md
- CONTRIBUTING.md
- ARCHITECTURE.md
- UI-AUDIT-FIX-SPEC.md

Review for:
- broken routes
- broken Blade components
- authorization/permission mismatches
- user actions visible when backend will 403
- signed-out states
- admin-only actions exposed publicly
- duplicate responsive markup
- aria-hidden misuse
- keyboard navigation issues
- focus states
- color contrast
- mobile overflow
- empty/error state regressions
- theme region regressions
- sandbox/template/slot-outlet regressions
- public/build or npm build drift
- missing tests
- baseline shared-host compatibility problems

Deliver:
1. Summary verdict: approve / request changes
2. High-severity issues
3. Medium-severity issues
4. Low-severity polish
5. Exact files/lines to inspect
6. Suggested fixes
7. Tests that should be added
8. Manual browser checks needed

Be direct. Do not rubber-stamp the PR.
```


## Codex prompt: accessibility and mobile test hardening

```text
Add or improve tests for NovFora UI accessibility and mobile-critical behavior.

Read:
- CONTRIBUTING.md
- UI-AUDIT-FIX-SPEC.md
- existing Pest/Dusk tests

Focus areas:
- public layout/header/mobile menu
- search
- forum index
- forum show/topic list
- topic/thread view
- auth/register/login
- signed-out CTAs
- permission-aware action visibility
- admin dashboard shell if present

Test goals:
1. Important pages render without errors.
2. Signed-out users see appropriate CTAs.
3. Unauthorized users do not see restricted actions.
4. Authorized moderators/admins do see valid actions.
5. Mobile nav does not expose duplicate critical content where testable.
6. Forms show validation errors accessibly.
7. Empty states render helpful copy.
8. Topic/post actions match backend policies.

Rules:
- Use existing test framework.
- Prefer behavior tests over brittle snapshots.
- Do not fake permissions unrealistically.
- Keep tests maintainable.
- Avoid adding heavy new test dependencies.

Deliver:
- test files changed
- coverage summary
- remaining manual accessibility checks
```


## Antigravity prompt: isolated frontend prototypes

```text
Create isolated NovFora UI/UX prototypes without overwriting production code.

First read:
- README.md
- ARCHITECTURE.md
- CLAUDE.md
- PROJECT-STATE.md

Inspect current pages:
- public forum index
- forum/topic list
- topic/thread view
- login/register
- admin dashboard if available

Prototype three directions:
1. Cozy editorial community
2. Premium SaaS forum/admin hybrid
3. Classic forum modernized

Brand direction:
Warm indie web + premium SaaS clarity + classic forum density.

Rules:
- Work in an isolated branch or prototype area.
- Do not permanently replace existing implementation.
- Reuse existing Laravel/Blade/Tailwind patterns.
- Do not add dependencies.
- Do not copy competitor layouts directly.
- Preserve NovFora's forum density and self-hosted identity.

Deliver:
1. Screenshots or browser-verified captures for desktop and mobile
2. What changed visually
3. What backend data each direction needs
4. Which direction best fits NovFora
5. Implementation plan to bring the best ideas into production incrementally
6. Risks or accessibility concerns
```


## Antigravity prompt: browser QA pass

```text
Run a browser-based QA pass on NovFora's public community UI.

Target pages:
- community/forum index
- forum board page
- topic/thread page
- register
- login
- search
- groups/clubs if public
- admin dashboard if accessible in local/dev context

Check:
- desktop layout
- mobile portrait layout
- mobile menu overflow
- duplicated visible content
- duplicate screen-reader-looking content where detectable
- broken nav/search
- empty states
- signed-out CTAs
- topic/post action clarity
- registration form usability
- visual hierarchy
- obvious accessibility problems

Deliver:
1. Screenshots or recordings
2. Issues found
3. Severity ranking
4. Exact page/component affected
5. Suggested code-level fix
6. Which issues belong in Cursor vs Claude Code vs Codex
```


## Manus prompt: competitor UX research

```text
Research forum/community UI and admin UX patterns for NovFora.

Compare:
- Discourse
- XenForo
- Invision Community
- NodeBB
- Flarum
- phpBB
- MyBB
- Simple Machines Forum
- Circle
- Discord/Slack-style communities where relevant

NovFora positioning:
- open-source
- self-hosted
- shared-host friendly
- owner-first
- no lock-in
- classic forum depth with modern polish
- warm indie-web identity

Focus:
1. Forum index UX
2. Topic list UX
3. Thread reading UX
4. Composer/reply UX
5. Notifications
6. User profiles
7. Mobile navigation
8. Moderation workflows
9. Admin dashboard
10. Permissions management
11. Theme/customization UX
12. Onboarding new members

Deliver:
- comparison matrix
- best patterns NovFora should borrow conceptually
- patterns NovFora should avoid
- opportunities for NovFora to differentiate
- recommended UX principles
- website copy ideas explaining why NovFora is different

Important:
Do not produce code.
Do not recommend copying proprietary UI directly.
Translate findings into original NovFora-specific UX principles.
```


## Manus prompt: UX copy and microcopy

```text
Create a NovFora UX copy and microcopy system.

Brand voice:
- warm
- clear
- independent
- trustworthy
- owner-first
- lightly editorial
- not corporate
- not childish
- not overly cute

Create copy for:
1. community welcome panel
2. start-here onboarding
3. empty forum
4. empty topic list
5. empty search results
6. signed-out reply CTA
7. registration anti-spam explanation
8. permission denied
9. report submitted
10. topic created
11. post edited
12. private message sent
13. notifications
14. moderation queue
15. admin dashboard intro
16. permission inspector
17. theme customization
18. plugin manager
19. upgrade safety center
20. migration/importer screens

Deliver:
- primary copy
- alternate variants
- tone rules
- words/phrases to avoid
- short labels for buttons and empty states
- longer explanatory copy for admin screens
```
