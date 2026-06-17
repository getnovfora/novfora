<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Guard for the dev.novfora.com login-token regression (P5 deploy gap). Once lang/en/auth.php exists it
| OVERRIDES the framework's auth.* namespace, so the file must carry the scaffolding strings (failed /
| throttle) AND the full login UI group — otherwise __() echoes the raw "auth.*" key straight onto the page.
| `auth.password` is intentionally the forgot-password group (not the framework string), so we don't assert
| it here.
*/

uses(RefreshDatabase::class);

it('resolves the framework auth scaffolding strings instead of the raw token', function () {
    expect(trans('auth.failed'))->not->toBe('auth.failed');
    expect(trans('auth.throttle'))->not->toBe('auth.throttle');

    // `auth.password` is DELIBERATELY the forgot-password UI group (an array), NOT the framework scalar
    // string: nothing reads __('auth.password') as a string here (UpdateUserPassword supplies its own
    // current_password message). Asserting the array shape documents + guards that decision — if someone
    // ever replaced it with the framework scalar, this flags it.
    expect(trans('auth.password'))->toBeArray();
});

it('resolves every login-screen key the view references', function () {
    $keys = [
        'title', 'email_label', 'password_label', 'remember_me', 'submit', 'forgot_password', 'create_account',
        // The social-login block keys — only rendered when a provider is configured, so GET /login alone
        // can't guard them; assert them directly so a deleted/mistyped key is still caught.
        'social_area_label', 'continue_with', 'or_password',
    ];

    foreach ($keys as $key) {
        expect(trans("auth.login.{$key}"))->not->toBe("auth.login.{$key}");
    }
});

it('renders the login page without leaking any raw auth.login.* token', function () {
    $this->seed();

    $response = $this->get('/login');

    $response->assertOk();
    expect($response->getContent())->not->toContain('auth.login.');
    $response->assertSee('Sign in')->assertSee('Email')->assertSee('Password');
});
