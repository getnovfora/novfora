<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Permission;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Acl;

/*
| Polish R3 — the Permission Inspector's plain-language explanation layer (admin.inspector.*). For every
| reason code PermissionResolver can emit, an Acl::make() fixture produces it, then we assert the readable
| explanation states the HUMAN pieces — the permission LABEL (not the raw key), the scope NAME, the holder
| NAME, and the right verdict — never leaks a raw holder/scope code (group#N / forum:N / user#N) into the
| readable block, and that the admin.inspector.* keys all resolve. The layer is pure presentation: it must
| FAITHFULLY represent what the resolver decided (NEVER is absolute; a sub-global grant overrides; a ban
| pre-empts every grant).
*/

uses(RefreshDatabase::class);

const PERM_KEY = 'topic.moderate';
const PERM_LABEL = 'Moderate topics';
const PERM_DESC = 'Lock, move, merge, and delete topics in a forum.';

/** Seed the catalog row whose label + description the readable layer surfaces. */
function seedPermissionCatalog(): void
{
    Permission::create([
        'key' => PERM_KEY,
        'label' => PERM_LABEL,
        'scope_kind' => 'forum',
        'group' => 'Moderation',
        'description' => PERM_DESC,
    ]);
}

/**
 * Drive the Livewire panel and return [testable, report, explanation].
 *
 * @return array{0:Testable, 1:array<string,mixed>, 2:array<string,mixed>}
 */
function inspectExplain(int $userId, string $scopeRef): array
{
    $c = Livewire::test('admin.permission-inspector')
        ->set('userRef', (string) $userId)
        ->set('permission', PERM_KEY)
        ->set('scopeRef', $scopeRef)
        ->call('inspect')
        ->assertSet('error', null);

    return [$c, $c->instance()->report, $c->instance()->explanation()];
}

/** No raw holder/scope code, and no unresolved i18n key, may appear in a readable string. */
function assertNoRawCodes(string $s): void
{
    foreach (['group#', 'user#', 'forum:', 'category:', 'thread:', 'club:', ':*'] as $code) {
        expect($s)->not->toContain($code);
    }
    expect($s)->not->toContain('admin.inspector.');
}

it('explains group_allow by naming the granting group, the forum, and the override', function () {
    seedPermissionCatalog();
    $acl = Acl::make();
    $acl->forum->update(['title' => 'General Discussion']);
    $acl->group('moderators', ['name' => 'Moderators']);
    $u = $acl->user(['moderators'], ['username' => 'tommy']);
    $acl->grant('moderators', PERM_KEY, $acl->forumScope, V::Allow);

    [$c, $report, $exp] = inspectExplain($u->id, 'forum:'.$acl->forum->id);

    expect($report['reason'])->toBe('group_allow');
    expect($exp['verdict'])->toBe('Allowed');
    expect($exp['sentence'])
        ->toContain('tommy')
        ->toContain('moderate topics')      // the LABEL (lower-cased into the verb slot), not the raw key
        ->toContain('Moderators')           // the granting group, by NAME
        ->toContain('General Discussion')   // the forum, by NAME
        ->toContain('overrides')            // grant below global → override note
        ->not->toContain(PERM_KEY);         // the raw permission key never appears in the prose
    expect($exp['permission_label'])->toBe(PERM_LABEL);
    expect($exp['permission_description'])->toBe(PERM_DESC);
    expect($exp['decided_by_name'])->toBe('Moderators');
    expect($exp['scope_name'])->toContain('General Discussion');
    assertNoRawCodes($exp['sentence']);

    // The readable block renders the human pieces + the description sub-line; every i18n key resolves.
    $c->assertSee('moderate topics')
        ->assertSee('Moderators')
        ->assertSee('General Discussion')
        ->assertSee(PERM_DESC)
        ->assertSee('Allowed')
        ->assertDontSee('admin.inspector.');
});

it('explains user_allow as a direct grant on the account', function () {
    seedPermissionCatalog();
    $acl = Acl::make();
    $acl->forum->update(['title' => 'General Discussion']);
    $u = $acl->user(['members'], ['username' => 'tommy']);
    $acl->grant($u, PERM_KEY, $acl->forumScope, V::Allow);   // a rule on the user's own account

    [, $report, $exp] = inspectExplain($u->id, 'forum:'.$acl->forum->id);

    expect($report['reason'])->toBe('user_allow');
    expect($exp['verdict'])->toBe('Allowed');
    expect($exp['sentence'])
        ->toContain('tommy')
        ->toContain('moderate topics')
        ->toContain('their account')
        ->toContain('General Discussion')
        ->toContain('overrides');
    expect($exp['decided_by_name'])->toBe('tommy');
    assertNoRawCodes($exp['sentence']);
});

it('explains a NEVER as an absolute deny that names the holding group', function () {
    seedPermissionCatalog();
    $acl = Acl::make();
    $acl->forum->update(['title' => 'General Discussion']);
    $acl->group('moderators', ['name' => 'Moderators']);
    $u = $acl->user(['moderators'], ['username' => 'tommy']);
    $acl->grant('moderators', PERM_KEY, $acl->forumScope, V::Allow);
    $acl->grant('moderators', PERM_KEY, $acl->forumScope, V::Never);

    [, $report, $exp] = inspectExplain($u->id, 'forum:'.$acl->forum->id);

    expect($report['reason'])->toBe('never');
    expect($exp['verdict'])->toBe('Denied');
    expect($exp['sentence'])
        ->toContain('hard-denied')
        ->toContain('NEVER')
        ->toContain('Moderators')               // the group holding the NEVER, by NAME
        ->toContain('cannot be overridden')
        ->not->toContain('overrides');          // a NEVER is never framed as an override
    expect($exp['decided_by_name'])->toBe('Moderators');
    assertNoRawCodes($exp['sentence']);
});

it('explains a ban as taking priority over every grant', function () {
    seedPermissionCatalog();
    $acl = Acl::make();
    $u = $acl->user(['members'], ['username' => 'tommy']);
    $acl->grant('members', PERM_KEY, $acl->forumScope, V::Allow);   // a grant the ban must pre-empt
    $acl->ban($u);                                                  // global ban

    [, $report, $exp] = inspectExplain($u->id, 'forum:'.$acl->forum->id);

    expect($report['reason'])->toBe('banned');
    expect($exp['verdict'])->toBe('Denied');
    expect($exp['sentence'])
        ->toContain('tommy')
        ->toContain('banned')
        ->toContain('moderate topics')
        ->toContain('priority');
    // The ban holder must NOT assert a scope: BanChecker matches a global OR a scoped ban and can't tell which.
    expect($exp['decided_by_name'])->toBe('an active ban');
    assertNoRawCodes($exp['sentence']);
});

it('explains deny-by-default when no rule grants it', function () {
    seedPermissionCatalog();
    $acl = Acl::make();
    $acl->forum->update(['title' => 'General Discussion']);
    $u = $acl->user(['members'], ['username' => 'tommy']);
    // no grants — nothing in the scope chain

    [, $report, $exp] = inspectExplain($u->id, 'forum:'.$acl->forum->id);

    expect($report['reason'])->toBe('default');
    expect($exp['verdict'])->toBe('Denied');
    expect($exp['sentence'])
        ->toContain('tommy')
        ->toContain('moderate topics')
        ->toContain('denied by default')
        ->toContain('General Discussion');       // names the inspected scope when none decided
    expect($exp['decided_by_name'])->toBe('no matching rule');
    assertNoRawCodes($exp['sentence']);
});

it('falls back to the raw key (without erroring) for a permission not in the catalog', function () {
    // No catalog row seeded: the label degrades to the key, the description is absent, nothing throws.
    $acl = Acl::make();
    $u = $acl->user(['members'], ['username' => 'tommy']);
    $acl->grant('members', PERM_KEY, $acl->global, V::Allow);

    [, $report, $exp] = inspectExplain($u->id, 'global');

    expect($report['reason'])->toBe('group_allow');
    expect($exp['permission_label'])->toBe(PERM_KEY);
    expect($exp['permission_description'])->toBeNull();
    expect($exp['scope_name'])->toBe('site-wide');        // global → site-wide, no override note
    expect($exp['sentence'])
        ->toContain('site-wide')                          // the sentence reads "grants it site-wide", not "in …"
        ->not->toContain('overrides');
});
