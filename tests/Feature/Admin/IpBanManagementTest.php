<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\RegistrationGuard;
use App\Models\Ban;
use App\Moderation\IpBanGuard;
use App\Moderation\IpBanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U13 (NOV-111, ADR-0121) — IP/range ban enforcement + management + investigation. Range bans (stored but
| matched nowhere before) are now enforced; the ACP surface is users.manage-gated.
*/

uses(RefreshDatabase::class);

it('enforces a range ban at registration (the gap this slice closes)', function () {
    $this->seed();
    app(IpBanService::class)->create('range', '203.0.113.0/24', 'abusive block', null);

    $guard = app(RegistrationGuard::class);
    // An address inside the banned /24 is BLOCKED; one outside is not.
    expect($guard->screen(['email' => 'a@x.test', 'username' => 'a', 'ip' => '203.0.113.77'])->blocked())->toBeTrue()
        ->and($guard->screen(['email' => 'b@x.test', 'username' => 'b', 'ip' => '203.0.114.1'])->blocked())->toBeFalse();
});

it('enforces an exact IP ban and an email ban at registration', function () {
    $this->seed();
    app(IpBanService::class)->create('ip', '198.51.100.9', null, null);
    app(IpBanService::class)->create('email', 'spammer@x.test', null, null);

    $guard = app(RegistrationGuard::class);
    expect($guard->screen(['email' => 'ok@x.test', 'username' => 'a', 'ip' => '198.51.100.9'])->blocked())->toBeTrue()
        ->and($guard->screen(['email' => 'spammer@x.test', 'username' => 'b', 'ip' => '198.51.100.10'])->blocked())->toBeTrue()
        ->and($guard->screen(['email' => 'ok@x.test', 'username' => 'c', 'ip' => '198.51.100.10'])->blocked())->toBeFalse();
});

it('normalises a bare address to an exact ip ban and a prefixed one to a range ban', function () {
    $this->seed();
    $exact = app(IpBanService::class)->create('ip', '203.0.113.5/32', null, null);
    $range = app(IpBanService::class)->create('range', '203.0.113.0/24', null, null);

    expect($exact->type)->toBe('ip')->and($exact->value)->toBe('203.0.113.5')
        ->and($range->type)->toBe('range')->and($range->value)->toBe('203.0.113.0/24');

    expect(fn () => app(IpBanService::class)->create('ip', 'not-an-ip', null, null))
        ->toThrow(InvalidArgumentException::class);
});

it('the guard cache invalidates on a lift so enforcement is immediate', function () {
    $this->seed();
    $ban = app(IpBanService::class)->create('ip', '198.51.100.9', null, null);
    expect(app(IpBanGuard::class)->isBanned('198.51.100.9'))->toBeTrue();

    app(IpBanService::class)->lift($ban);
    expect(app(IpBanGuard::class)->isBanned('198.51.100.9'))->toBeFalse();
});

it('lets a 2FA admin manage bans + look up an IP through the ACP', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.moderation.ip-bans')
        ->set('type', 'range')
        ->set('value', '203.0.113.0/24')
        ->set('reason', 'block')
        ->call('save')
        ->assertHasNoErrors();
    expect(Ban::where('type', 'range')->where('value', '203.0.113.0/24')->exists())->toBeTrue();

    // The investigation lookup reports ban status for an address in the block.
    Livewire::test('admin.moderation.ip-bans')
        ->set('lookup', '203.0.113.50')
        ->assertSee('Banned');

    // An invalid CIDR surfaces a field error, not a crash.
    Livewire::test('admin.moderation.ip-bans')
        ->set('type', 'range')->set('value', 'garbage')->call('save')
        ->assertHasErrors('value');
});

it('gates the IP-bans page behind bans.manage + users.manage (403 for a plain member)', function () {
    $this->seed();
    $this->actingAs(Users::inGroups(['members']));
    Livewire::test('admin.moderation.ip-bans')->assertStatus(403);
});
