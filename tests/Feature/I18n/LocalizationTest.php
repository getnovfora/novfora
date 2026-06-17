<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| i18n framework (Wave 8.1) — locale resolution precedence, the allowlist guard on untrusted ?locale input,
| profile/session persistence, and the RTL <html dir> switch. `en` ships strings; other locales are
| scaffolding (registered, RTL/switcher exercised, strings fall back to en).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

// ── allowlist helper ─────────────────────────────────────────────────────────────────────────────────────

it('guards the locale allowlist and reports writing direction', function () {
    expect(Locales::isSupported('en'))->toBeTrue()
        ->and(Locales::isSupported('ar'))->toBeTrue()
        ->and(Locales::isSupported('zz'))->toBeFalse()
        ->and(Locales::isSupported(null))->toBeFalse()
        ->and(Locales::direction('en'))->toBe('ltr')
        ->and(Locales::direction('ar'))->toBe('rtl')
        ->and(Locales::direction('zz'))->toBe('ltr')   // unknown → safe LTR default
        ->and(Locales::isRtl('he'))->toBeTrue()
        ->and(Locales::default())->toBe('en');
});

// ── resolution precedence ────────────────────────────────────────────────────────────────────────────────

it('resolves a signed-in member’s stored locale', function () {
    $user = Users::inGroups(['members']);
    $user->forceFill(['locale' => 'es'])->save();

    $this->actingAs($user)->get(route('forums.index'))->assertOk();

    expect(app()->getLocale())->toBe('es');
});

it('falls back to the default for an unsupported stored locale', function () {
    $user = Users::inGroups(['members']);
    $user->forceFill(['locale' => 'zz'])->save();   // not in the allowlist

    $this->actingAs($user)->get(route('forums.index'))->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('uses the session locale for a guest', function () {
    $this->withSession(['locale' => 'fr'])->get(route('forums.index'))->assertOk();

    expect(app()->getLocale())->toBe('fr');
});

// ── RTL scaffolding ──────────────────────────────────────────────────────────────────────────────────────

it('emits dir="rtl" on the document for an RTL locale', function () {
    $user = Users::inGroups(['members']);
    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user)->get(route('forums.index'))
        ->assertOk()->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false);
});

it('emits dir="ltr" on the document for the default LTR locale', function () {
    $this->get(route('forums.index'))->assertOk()->assertSee('dir="ltr"', false);
});

// ── language switcher ────────────────────────────────────────────────────────────────────────────────────

it('persists a valid locale choice to the session and the member profile', function () {
    $user = Users::inGroups(['members']);

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'de'])
        ->assertRedirect()->assertSessionHas('locale', 'de');

    expect($user->fresh()->locale)->toBe('de');
});

it('rejects an out-of-allowlist locale and leaves the session untouched', function () {
    $this->post(route('locale.update'), ['locale' => 'zz'])
        ->assertSessionHasErrors('locale');

    expect(session('locale'))->toBeNull();
});

// ── externalised strings ─────────────────────────────────────────────────────────────────────────────────

it('serves externalised UI strings and pluralises the result word', function () {
    app()->setLocale('en');

    expect(__('search.save_this'))->toBe('Save this search')
        ->and(__('common.delete'))->toBe('Delete')
        ->and(trans_choice('search.result_word', 1))->toBe('result')
        ->and(trans_choice('search.result_word', 5))->toBe('results');
});

// ── P5.3: the proof locale (es) + per-key fallback to en ──────────────────────────────────────────────────

it('renders the Spanish proof locale for the externalised catalogues', function () {
    app()->setLocale('es');

    expect(__('auth.login.title'))->toBe('Iniciar sesión')
        ->and(__('common.save'))->toBe('Guardar')
        ->and(__('errors.404.title'))->toBe('Página no encontrada')
        ->and(__('search.placeholder'))->toBe('Buscar publicaciones…')
        ->and(trans_choice('search.result_word', 5))->toBe('resultados'); // pluralisation localised too
});

it('falls back to en for a registered-but-untranslated locale (per-key)', function () {
    // fr is in the allowlist but ships no lang/fr/ catalogue → every key resolves to its en value.
    app()->setLocale('fr');

    expect(__('auth.login.title'))->toBe('Sign in')
        ->and(__('errors.404.title'))->toBe('Page not found')
        ->and(__('common.save'))->toBe('Save');
});
