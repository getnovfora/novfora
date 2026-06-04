<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
| Phase-1.5 F-I: authentication events (login / failed login / logout / 2FA / reset) are recorded to the
| append-only audit_log (actor, event, ip, ua) — the auth half of brief §4 "audit logging of security-
| relevant events", which the moderation/anti-spam actions already covered.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('audit-logs a successful login and a failed login', function () {
    $user = User::factory()->create(['password' => Hash::make('correct horse battery staple')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);   // failed
    $this->post('/login', ['email' => $user->email, 'password' => 'correct horse battery staple']); // ok

    expect(AuditLog::where('action', 'auth.failed')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'auth.login')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('audit-logs a logout with the actor and ip', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $row = AuditLog::where('action', 'auth.logout')->where('actor_id', $user->id)->first();
    expect($row)->not->toBeNull();
    expect($row->ip_address)->not->toBeNull();
});
