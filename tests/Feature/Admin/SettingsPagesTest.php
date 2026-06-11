<?php

// SPDX-License-Identifier: Apache-2.0

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function admin2fa(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

function bag(): Settings
{
    return app(Settings::class);
}

// ── General ──────────────────────────────────────────────────────────────────────────────────────────
it('saves general settings through the panel', function () {
    Livewire::actingAs(admin2fa())->test('admin.settings.general')
        ->set('siteName', 'Campfire')
        ->set('siteNotice', 'Welcome, everyone!')
        ->call('save')->assertHasNoErrors();

    expect(bag()->string('general.site_name'))->toBe('Campfire');
    expect(bag()->string('general.site_notice'))->toBe('Welcome, everyone!');
});

it('shows the site notice site-wide once set', function () {
    bag()->set('general.site_notice', 'Scheduled maintenance Sunday.');

    $this->get(route('forums.index'))->assertOk()->assertSee('Scheduled maintenance Sunday.');
});

it('takes the board offline for guests but lets admins through', function () {
    bag()->set('general.board_offline', true);

    $this->get(route('forums.index'))->assertStatus(503)->assertSee('We’ll be back soon', false);
    $this->actingAs(admin2fa())->get(route('forums.index'))->assertOk();
});

// ── Registration ─────────────────────────────────────────────────────────────────────────────────────
it('blocks sign-up when registration is disabled', function () {
    bag()->set('registration.enabled', false);

    $this->get('/register')->assertOk()->assertSee('Registration closed');
    $this->post('/register', [
        'username' => 'newbie', 'email' => 'newbie@novfora.test',
        'password' => 'Sup3rSecret!23', 'password_confirmation' => 'Sup3rSecret!23',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'newbie@novfora.test')->exists())->toBeFalse();
});

it('auto-verifies new users when email verification is turned off', function () {
    bag()->set('registration.enabled', true);
    bag()->set('registration.require_email_verification', false);

    $user = app(CreateNewUser::class)->create([
        'username' => 'verified', 'email' => 'verified@novfora.test',
        'password' => 'Sup3rSecret!23', 'password_confirmation' => 'Sup3rSecret!23',
    ]);

    expect($user->email_verified_at)->not->toBeNull();
});

// ── Email ────────────────────────────────────────────────────────────────────────────────────────────
it('stores the SMTP password encrypted, never echoes it, and keeps it on a blank re-save', function () {
    Livewire::actingAs(admin2fa())->test('admin.settings.email')
        ->set('host', 'smtp.example.com')
        ->set('password', 'smtp-secret')
        ->call('save')->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertSet('passwordSet', true);

    expect(bag()->get('mail.password'))->toBe('smtp-secret');

    // A fresh visit never pre-fills the secret; a blank save keeps it.
    Livewire::actingAs(admin2fa())->test('admin.settings.email')
        ->assertSet('password', '')
        ->call('save');

    expect(bag()->get('mail.password'))->toBe('smtp-secret');
});

it('sends a test email and surfaces the result', function () {
    Livewire::actingAs(admin2fa())->test('admin.settings.email')
        ->set('testTo', 'me@example.com')
        ->call('sendTest')
        ->assertSet('testVariant', 'success')
        ->assertSee('Test email sent');
});

// ── Moderation ───────────────────────────────────────────────────────────────────────────────────────
it('overrides the live moderation config when saved', function () {
    Livewire::actingAs(admin2fa())->test('admin.settings.moderation')
        ->set('holdPosts', 0)
        ->call('save')->assertHasNoErrors();

    expect(bag()->int('moderation.new_user_hold_posts'))->toBe(0);

    bag()->applyToConfig();
    expect(config('novfora.antispam.new_user_moderation.posts'))->toBe(0);
});

// ── Anti-spam ────────────────────────────────────────────────────────────────────────────────────────
it('saves the anti-spam captcha + SFS settings', function () {
    Livewire::actingAs(admin2fa())->test('admin.settings.antispam')
        ->set('captchaProvider', 'none')
        ->set('sfsUseApi', false)
        ->call('save')->assertHasNoErrors();

    expect(bag()->string('antispam.captcha_provider'))->toBe('none');
    expect(bag()->bool('antispam.sfs_use_api'))->toBeFalse();

    bag()->applyToConfig();
    expect(config('novfora.antispam.registration.captcha.provider'))->toBe('none');
});

// ── Appearance ───────────────────────────────────────────────────────────────────────────────────────
it('saves appearance settings and the layout reflects them', function () {
    Livewire::actingAs(admin2fa())->test('admin.settings.appearance')
        ->set('accentColor', '#ff8800')
        ->set('forumWidth', 'wide')
        ->set('wordmark', 'Campfire')
        ->call('save')->assertHasNoErrors();

    expect(bag()->string('appearance.accent_color'))->toBe('#ff8800');

    $html = $this->get(route('forums.index'))->assertOk();
    $html->assertSee('Campfire');                 // wordmark override
    $html->assertSee('--layout-max-width:80rem', false); // wide → 80rem token override
    $html->assertSee('#ff8800', false);           // accent override emitted
});
