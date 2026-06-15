<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// SAML SSO is a SCAFFOLD (Phase 4 · M2.4) — NOT validated against a real IdP. These tests prove the detection
// gate (inert by default), the provider contract, and the pre-linked sign-in path using a FAKE provider bound
// in the container. There is no real SAML protocol / XML-signature code under test.

use App\Auth\Saml\Contracts\SamlProvider;
use App\Auth\Saml\SamlAssertion;
use App\Auth\Saml\SamlException;
use App\Models\SocialAccount;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** A stand-in SamlProvider so the scaffold can be exercised without a real IdP. */
function fakeSamlProvider(bool $configured = true): SamlProvider
{
    return new class($configured) implements SamlProvider
    {
        public function __construct(private bool $configured) {}

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function loginUrl(?string $relayState = null): string
        {
            return 'https://idp.example.test/sso';
        }

        public function consume(string $samlResponse): SamlAssertion
        {
            if ($samlResponse === 'INVALID') {
                throw new SamlException('bad signature');
            }

            return new SamlAssertion(nameId: $samlResponse, email: 'subject@idp.example.test');
        }

        public function metadata(): string
        {
            return '<EntityDescriptor entityID="novfora-sp"/>';
        }
    };
}

function bindSaml(?SamlProvider $provider, bool $enabled = true): void
{
    if ($provider !== null) {
        app()->instance(SamlProvider::class, $provider);
    }
    app(Settings::class)->set('auth.saml.enabled', $enabled);
}

// ── Detection: inert by default ──────────────────────────────────────────────────────────────────────────

it('404s every SAML route when no provider is bound (the default)', function () {
    app(Settings::class)->set('auth.saml.enabled', true); // even toggled on…

    $this->get(route('saml.login'))->assertNotFound();
    $this->post(route('saml.acs'), ['SAMLResponse' => 'x'])->assertNotFound();
    $this->get(route('saml.metadata'))->assertNotFound();
});

it('404s when a provider is bound but the setting is off', function () {
    bindSaml(fakeSamlProvider(), enabled: false);

    $this->get(route('saml.login'))->assertNotFound();
});

it('404s when the bound provider is not configured', function () {
    bindSaml(fakeSamlProvider(configured: false), enabled: true);

    $this->get(route('saml.login'))->assertNotFound();
});

// ── Enabled + configured: the scaffold flow ──────────────────────────────────────────────────────────────

it('redirects to the IdP SSO URL when SAML is available', function () {
    bindSaml(fakeSamlProvider());

    $this->get(route('saml.login'))->assertRedirect('https://idp.example.test/sso');
});

it('serves SP metadata when SAML is available', function () {
    bindSaml(fakeSamlProvider());

    $this->get(route('saml.metadata'))
        ->assertOk()
        ->assertSee('EntityDescriptor', false)
        ->assertHeader('Content-Type', 'application/samlmetadata+xml');
});

it('signs in a pre-linked subject at the ACS', function () {
    bindSaml(fakeSamlProvider());
    $user = Users::inGroups(['members', 'tl1'], ['email' => 'saml-user@idp.example.test']);
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'saml', 'provider_user_id' => 'subject-123', 'linked_at' => now()]);

    $this->post(route('saml.acs'), ['SAMLResponse' => 'subject-123'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('refuses an unknown subject at the ACS (no JIT provisioning in the scaffold)', function () {
    bindSaml(fakeSamlProvider());

    $this->post(route('saml.acs'), ['SAMLResponse' => 'never-seen'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
    $this->assertGuest();
});

it('fails closed on an invalid SAML response', function () {
    bindSaml(fakeSamlProvider());

    $this->post(route('saml.acs'), ['SAMLResponse' => 'INVALID'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');
    $this->assertGuest();
});

// ── The DTO contract ─────────────────────────────────────────────────────────────────────────────────────

it('carries the asserted identity in the SamlAssertion DTO', function () {
    $a = new SamlAssertion(nameId: 'abc', email: 'a@b.test', attributes: ['groups' => ['staff']]);

    expect($a->nameId)->toBe('abc');
    expect($a->email)->toBe('a@b.test');
    expect($a->attributes['groups'])->toBe(['staff']);
});
