<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\AntiSpam\Captcha\CaptchaManager;
use App\AntiSpam\RegistrationGuard;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user, then place them in the default groups.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30', Rule::unique(User::class)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
        ], [
            'username.alpha_dash' => 'The username may only contain letters, numbers, dashes and underscores.',
        ])->validate();

        // Anti-spam Layer 1 (ADR-0007 §2.2). Honeypot/timing and CAPTCHA are definitive bot signals → reject;
        // the crowdsourced/heuristic screener is tri-state (block | flag→moderation | allow) and degrades
        // gracefully, so a dead external service never stalls or errors registration.
        if ($this->looksAutomated($input)) {
            throw ValidationException::withMessages(['email' => 'Your submission looked automated. Please try again.']);
        }

        $captcha = app(CaptchaManager::class)->for('register');
        if (! $captcha->verify($input)) {
            throw ValidationException::withMessages([
                $captcha->challenge()['field'] ?? 'captcha_answer' => 'That answer was not correct — please try again.',
            ]);
        }

        $screening = app(RegistrationGuard::class)->screen([
            'email' => $input['email'],
            'username' => $input['username'],
            'ip' => (string) request()->ip(),
        ]);
        if ($screening->blocked()) {
            throw ValidationException::withMessages(['email' => 'Registration is not available from your network right now.']);
        }

        $user = new User;
        $user->fill([
            'username' => $input['username'],
            'name' => $input['username'],
            'display_name' => $input['username'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']), // argon2id (config/hashing.php)
        ]);
        // A flagged signup is created but held for moderation (status=pending); their early posts are
        // queued too (TL0). An allowed signup is active. Either way the account is real and recoverable.
        // `status` is set server-side (it is not in User's mass-assignable set), so a crafted register
        // payload can never choose its own account state.
        $user->status = $screening->flagged() ? 'pending' : 'active';
        $user->save();

        // Default membership: primary Members + the entry trust level (security §1 / ADR-0007).
        // Trust levels are ACL groups; promotion automation is M3.
        foreach (['members' => true, 'tl0' => false] as $slug => $isPrimary) {
            $group = Group::where('slug', $slug)->first();
            if ($group instanceof Group) {
                $user->groups()->attach($group->id, ['is_primary' => $isPrimary]);
            }
        }

        return $user;
    }

    /**
     * Honeypot + timing trap (ADR-0007 §2.2). The honeypot is a hidden field humans never see but bots fill;
     * the timing token is the (encrypted) form-render time — a submission faster than the floor is bot-like.
     * Both are local and frictionless. A missing/invalid timing token is ignored (the form may be stale).
     *
     * @param  array<string, mixed>  $input
     */
    private function looksAutomated(array $input): bool
    {
        $field = (string) config('hearth.antispam.registration.honeypot.field', 'hp_url');
        if (filled($input[$field] ?? null)) {
            return true; // the hidden trap was filled
        }

        $token = $input['hp_ts'] ?? null;
        if (is_string($token) && $token !== '') {
            try {
                $renderedAt = (int) decrypt($token); // matches encrypt() in the form (serialized payload)
                $minSeconds = (int) config('hearth.antispam.registration.honeypot.min_seconds', 2);
                if ($renderedAt > 0 && (now()->timestamp - $renderedAt) < $minSeconds) {
                    return true; // submitted implausibly fast
                }
            } catch (\Throwable) {
                // Undecryptable/old token — ignore timing; the honeypot + screener still apply.
            }
        }

        return false;
    }
}
