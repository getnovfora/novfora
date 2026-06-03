<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\Scope;

it('parses scope references', function () {
    expect(Scope::parse('global')->isGlobal())->toBeTrue();
    expect(Scope::parse('global:*')->isGlobal())->toBeTrue();

    $forum = Scope::parse('forum:2');
    expect($forum->type)->toBe('forum')->and($forum->id)->toBe(2);

    $thread = Scope::parse('thread:17');
    expect($thread->type)->toBe('thread')->and($thread->id)->toBe(17);

    $cat = Scope::parse('CATEGORY:3'); // case-insensitive type
    expect($cat->type)->toBe('category')->and($cat->id)->toBe(3);
});

it('rejects malformed scope references', function (string $bad) {
    expect(fn () => Scope::parse($bad))->toThrow(InvalidArgumentException::class);
})->with(['bogus', 'forum', 'forum:abc', 'planet:1', '']);

it('produces a stable key and matches by type+id', function () {
    expect(Scope::forum(2)->key())->toBe('forum:2');
    expect(Scope::global()->key())->toBe('global:*');

    expect(Scope::forum(2)->matches('forum', 2))->toBeTrue();
    expect(Scope::forum(2)->matches('forum', 3))->toBeFalse();
    expect(Scope::forum(2)->matches('thread', 2))->toBeFalse();
    expect(Scope::global()->matches('global', null))->toBeTrue();
});
