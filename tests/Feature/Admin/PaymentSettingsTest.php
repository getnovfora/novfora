<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\MembershipTier;
use App\Models\Setting;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Phase 4 · M5.3 — Admin → Settings → Payments. Staff-gated; secrets encrypted; cannot enable without a secret.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function payAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'], ['email' => 'pay-admin@acp.test']));
}

it('forbids a non-admin from the payment settings', function () {
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'pay-member@acp.test']);

    $this->actingAs($member)->get(route('admin.settings.payments'))->assertForbidden();
    Livewire::actingAs($member)->test('admin.settings.payments')->assertStatus(403);
});

it('will not enable Stripe without a secret key', function () {
    Livewire::actingAs(payAdmin())
        ->test('admin.settings.payments')
        ->set('enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(Settings::class)->bool('payments.stripe.enabled'))->toBeFalse();
});

it('enables Stripe with a secret and stores it encrypted', function () {
    Livewire::actingAs(payAdmin())
        ->test('admin.settings.payments')
        ->set('enabled', true)
        ->set('secretKey', 'sk_live_supersecret')
        ->set('webhookSecret', 'whsec_supersecret')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(Settings::class);
    expect($settings->bool('payments.stripe.enabled'))->toBeTrue();
    expect($settings->secretIsSet('payments.stripe.secret_key'))->toBeTrue();

    $raw = Setting::where('key', 'payments.stripe.secret_key')->value('value');
    expect($raw)->not->toBe('sk_live_supersecret'); // encrypted at rest
});

it('shows a subscribe button on the member page only when self-checkout is enabled', function () {
    MembershipTier::create(['name' => 'Gold', 'slug' => 'gold', 'price_cents' => 500, 'currency' => 'USD', 'interval' => 'monthly', 'perks' => ['tier.ad_free'], 'is_active' => true]);
    $member = Users::inGroups(['members', 'tl1'], ['email' => 'sub-btn@pay.test']);

    // Disabled → no button.
    $this->actingAs($member)->get(route('membership.index'))->assertOk()->assertDontSee('Subscribe');

    // Enable Stripe.
    $s = app(Settings::class);
    $s->set('payments.stripe.secret_key', 'sk_test_x');
    $s->set('payments.stripe.enabled', true);

    $this->actingAs($member)->get(route('membership.index'))->assertOk()->assertSee('Subscribe');
});
