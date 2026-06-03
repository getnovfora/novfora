<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Acl;

/*
| The engine is exposed through Laravel's Gate, deny-by-default (ADR-0006). A scope-based check
| ($user->can('perm', $scope) / Gate::allows('perm', $scope)) is answered by the resolver; any check
| without a Scope argument falls through to Laravel's normal Gate/policy pipeline.
*/

uses(RefreshDatabase::class);

const PG = 'forum.post';

beforeEach(function () {
    Cache::flush();
});

it('grants through $user->can() when the resolver allows', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PG, $acl->forumScope, V::Allow);

    expect($u->fresh()->can(PG, $acl->forumScope))->toBeTrue();
});

it('denies by default through $user->can() with no matching grant', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);

    expect($u->fresh()->can(PG, $acl->forumScope))->toBeFalse();
    expect($u->fresh()->cannot(PG, $acl->forumScope))->toBeTrue();
});

it('enforces NEVER through the Gate', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PG, $acl->global, V::Allow);
    $acl->grant('members', PG, $acl->forumScope, V::Never);

    expect(Gate::forUser($u->fresh())->allows(PG, $acl->forumScope))->toBeFalse();
    expect(Gate::forUser($u->fresh())->allows(PG, $acl->catScope))->toBeTrue(); // allowed elsewhere
});

it('routes Gate::allows() with a Scope argument to the resolver', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PG, $acl->forumScope, V::Allow);

    expect(Gate::forUser($u->fresh())->allows(PG, $acl->forumScope))->toBeTrue();
    expect(Gate::forUser($u->fresh())->denies(PG, $acl->subScope))->toBeFalse(); // inherited down
});

it('falls through to the normal pipeline for non-scope abilities (undefined → deny)', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);

    // No Scope argument → Gate::before returns null → undefined ability resolves to false.
    expect($u->fresh()->can('some.undefined.ability'))->toBeFalse();
});

it('exposes a direct canDo() helper on the user', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PG, $acl->forumScope, V::Allow);

    expect($u->fresh()->canDo(PG, $acl->forumScope))->toBeTrue();
    expect($u->fresh()->canDo(PG, $acl->catScope))->toBeFalse();
});
