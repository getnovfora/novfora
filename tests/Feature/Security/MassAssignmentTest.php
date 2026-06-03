<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Mass-assignment guards on User (security by default). trust_level / status drive authorization (BanChecker
| reads status; trust gating reads group membership) and signature_html / avatar_path / cover_path are
| server-computed — none may be set from request input, or a stray ->update($request->...) becomes a
| privilege-escalation or stored-XSS bug. Regression for the mass-assignment finding in SECURITY-REVIEW.md.
*/

uses(RefreshDatabase::class);

it('keeps privilege/state/HTML fields out of User::$fillable', function () {
    $user = new User;

    foreach (['trust_level', 'status', 'signature_html', 'avatar_path', 'cover_path'] as $field) {
        expect($user->isFillable($field))->toBeFalse("{$field} must not be mass-assignable");
    }
});

it('ignores attacker-supplied privilege and HTML fields when mass-creating a User', function () {
    $user = User::create([
        'username' => 'mallory',
        'name' => 'Mallory',
        'display_name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'a-password',
        // hostile extras that must NOT take effect:
        'trust_level' => 4,
        'status' => 'active',
        'signature_html' => '<script>alert(1)</script>',
        'avatar_path' => '../../etc/passwd',
    ]);

    expect($user->trust_level)->not->toBe(4);
    expect($user->signature_html)->toBeNull();
    expect($user->avatar_path)->toBeNull();
});

it('still lets server code set the guarded fields via forceFill', function () {
    $user = User::create([
        'username' => 'trusted', 'name' => 'T', 'display_name' => 'T',
        'email' => 't@example.test', 'password' => 'a-password',
    ]);

    $user->forceFill(['trust_level' => 3, 'status' => 'pending'])->save();

    expect($user->fresh()->trust_level)->toBe(3);
    expect($user->fresh()->status)->toBe('pending');
});
