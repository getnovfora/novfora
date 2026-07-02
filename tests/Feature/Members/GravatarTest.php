<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

/*
| Opt-in Gravatar avatars (U18, ADR-0107). PRIVACY FENCE: ships OFF; when the admin turns it on, the
| <x-ui.avatar> component emits a gravatar.com <img> URL (md5 of the normalised email) for members with
| no uploaded avatar — fetched by the BROWSER. The server itself never makes an HTTP call to gravatar.com
| (Http::assertNothingSent is part of the contract), and the guest/deleted branch never resolves one.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('renders the md5-hashed Gravatar URL when the admin opt-in is ON (normalising the email)', function () {
    Http::fake();
    app(Settings::class)->set('members.gravatar_enabled', true);
    $user = User::factory()->create(['email' => 'MiXeD.Case@Example.COM']);

    $html = Blade::render('<x-ui.avatar :user="$user" />', ['user' => $user]);

    expect($html)->toContain('https://www.gravatar.com/avatar/'.md5('mixed.case@example.com'));
    Http::assertNothingSent(); // the member's browser fetches the image — this server never does
});

it('emits no Gravatar URL by default (the setting ships OFF)', function () {
    Http::fake();
    $user = User::factory()->create(['email' => 'someone@example.com']);

    $html = Blade::render('<x-ui.avatar :user="$user" />', ['user' => $user]);

    expect($html)->not->toContain('gravatar.com');
    Http::assertNothingSent();
});

it('an uploaded avatar always wins — never falls through to Gravatar', function () {
    Http::fake();
    app(Settings::class)->set('members.gravatar_enabled', true);
    $user = User::factory()->create(['email' => 'someone@example.com']);
    $user->avatar_path = 'avatars/custom.png'; // not mass-assignable by design — set directly
    $user->save();

    $html = Blade::render('<x-ui.avatar :user="$user" />', ['user' => $user]);

    expect($html)->toContain('avatars/custom.png')
        ->not->toContain('gravatar.com');
    Http::assertNothingSent();
});

it('the guest/deleted-author and null-user branches never emit a Gravatar URL, even when ON', function () {
    Http::fake();
    app(Settings::class)->set('members.gravatar_enabled', true);

    $guest = Blade::render('<x-ui.avatar :guest="true" :user="$user" />', ['user' => null]);
    $anonymous = Blade::render('<x-ui.avatar :user="$user" />', ['user' => null]);

    expect($guest)->not->toContain('gravatar.com')
        ->and($anonymous)->not->toContain('gravatar.com');
    Http::assertNothingSent();
});
