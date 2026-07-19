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

it('enforces an IPv6 /32 range across the whole block (U13 apex HIGH — was downgraded to one host)', function () {
    $this->seed();
    $ban = app(IpBanService::class)->create('range', '2001:db8::/32', 'ipv6 abuse', null);

    // Stored as a RANGE (not silently collapsed to the single host 2001:db8::).
    expect($ban->type)->toBe('range')->and($ban->value)->toBe('2001:db8::/32');

    $guard = app(RegistrationGuard::class);
    // Any address inside the /32 is blocked; an address outside it is not.
    expect($guard->screen(['email' => 'a@x.test', 'username' => 'a', 'ip' => '2001:db8:1234::5678'])->blocked())->toBeTrue()
        ->and($guard->screen(['email' => 'b@x.test', 'username' => 'b', 'ip' => '2001:db9::1'])->blocked())->toBeFalse();
});

it('matches an exact IPv6 ban regardless of the textual spelling (U13 apex MEDIUM — canonicalisation)', function () {
    $this->seed();
    // Admin bans the address as it appears in their logs — uppercase, non-canonical.
    $ban = app(IpBanService::class)->create('ip', '2001:DB8::1', null, null);
    expect($ban->value)->toBe('2001:db8::1'); // stored canonical

    // The live request arrives in canonical lowercase form and is still caught.
    expect(app(IpBanGuard::class)->isBanned('2001:db8::1'))->toBeTrue()
        ->and(app(IpBanGuard::class)->isBanned('2001:0db8:0000:0000:0000:0000:0000:0001'))->toBeTrue()
        // A dual-stack client presenting the IPv4-mapped form is caught by a plain IPv4 ban.
        ->and(app(IpBanService::class)->create('ip', '198.51.100.9', null, null))
        ->and(app(IpBanGuard::class)->isBanned('::ffff:198.51.100.9'))->toBeTrue();
});

it('refuses a /0 catch-all that would ban everyone (U13 apex MEDIUM — self-lockout guard)', function () {
    $this->seed();
    expect(fn () => app(IpBanService::class)->create('range', '0.0.0.0/0', 'oops', null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(IpBanService::class)->create('range', '::/0', 'oops', null))
        ->toThrow(InvalidArgumentException::class);
    // No ban row was written, so registration is not disabled.
    expect(Ban::whereIn('type', ['ip', 'range'])->count())->toBe(0);
});

it('the POST /bans route also routes value bans through the hardened service (U13 apex MEDIUM — 2nd write path)', function () {
    $this->seed();
    $admin = Users::inGroups(['admins']); // holds bans.manage
    // The /0 catch-all is refused here too — no row, registration stays open.
    $this->actingAs($admin)
        ->post(route('bans.store'), ['type' => 'range', 'value' => '0.0.0.0/0', 'reason' => 'oops'])
        ->assertRedirect();
    expect(Ban::whereIn('type', ['ip', 'range'])->count())->toBe(0);

    // A CIDR submitted as type=ip is reclassified to a range (not stored as an un-matchable exact ip).
    $this->actingAs($admin)
        ->post(route('bans.store'), ['type' => 'ip', 'value' => '203.0.113.0/24'])
        ->assertRedirect();
    $ban = Ban::whereIn('type', ['ip', 'range'])->firstOrFail();
    expect($ban->type)->toBe('range')->and($ban->value)->toBe('203.0.113.0/24')
        ->and(app(IpBanGuard::class)->isBanned('203.0.113.50'))->toBeTrue();
});

it('enforces a mixed-case email ban (U13 apex LOW — value lowercased to match the guard)', function () {
    $this->seed();
    app(IpBanService::class)->create('email', 'Spammer@X.test', null, null);
    expect(Ban::where('type', 'email')->value('value'))->toBe('spammer@x.test');

    $guard = app(RegistrationGuard::class);
    expect($guard->screen(['email' => 'spammer@x.test', 'username' => 'a', 'ip' => '198.51.100.1'])->blocked())->toBeTrue();
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
