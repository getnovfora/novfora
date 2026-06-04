<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Audit-logs security-relevant AUTHENTICATION events (phase-1.5 F-I) — login, logout, failed login,
 * lockout, password reset, and 2FA enable/confirm/disable — into the append-only audit_log (actor, event,
 * ip, ua). Brief §4 calls for "audit logging of security-relevant events"; the moderation/anti-spam actions
 * were already covered, this closes the auth gap.
 */
final class AuditAuthEvents
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
            TwoFactorAuthenticationEnabled::class => 'on2faEnabled',
            TwoFactorAuthenticationConfirmed::class => 'on2faConfirmed',
            TwoFactorAuthenticationDisabled::class => 'on2faDisabled',
        ];
    }

    public function onLogin(Login $e): void
    {
        $this->write('auth.login', $e->user);
    }

    public function onLogout(Logout $e): void
    {
        $this->write('auth.logout', $e->user);
    }

    public function onFailed(Failed $e): void
    {
        $this->write('auth.failed', $e->user, ['email' => $e->credentials['email'] ?? null]);
    }

    public function onLockout(Lockout $e): void
    {
        $this->write('auth.lockout', null, ['email' => $e->request->input('email')]);
    }

    public function onPasswordReset(PasswordReset $e): void
    {
        $this->write('auth.password_reset', $e->user);
    }

    public function on2faEnabled(TwoFactorAuthenticationEnabled $e): void
    {
        $this->write('auth.2fa.enabled', $e->user);
    }

    public function on2faConfirmed(TwoFactorAuthenticationConfirmed $e): void
    {
        $this->write('auth.2fa.confirmed', $e->user);
    }

    public function on2faDisabled(TwoFactorAuthenticationDisabled $e): void
    {
        $this->write('auth.2fa.disabled', $e->user);
    }

    /** @param array<string,mixed> $extra */
    private function write(string $action, ?Authenticatable $user, array $extra = []): void
    {
        $changes = array_filter(
            ['ua' => substr((string) request()->userAgent(), 0, 255)] + $extra,
            fn ($v) => $v !== null && $v !== '',
        );

        AuditLog::create([
            'actor_id' => $user?->getAuthIdentifier() ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $user ? $user::class : null,
            'auditable_id' => $user?->getAuthIdentifier(),
            'changes' => $changes !== [] ? $changes : null,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
