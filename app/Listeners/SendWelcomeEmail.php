<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Send the welcome email on registration (onboarding-lite, NOV-123) — mirrors {@see AwardJoinBadges}: QUEUED
 * off the registration hot path, tolerant of a since-deleted account. A missing/blank email is a silent no-op
 * (nothing to send to); the mail itself is queued, so the baseline tier delivers it within a cron interval.
 */
final class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function handle(Registered $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        if (! $user instanceof User || blank($user->email)) {
            return;
        }

        Mail::to($user->email)->queue(new WelcomeMail(
            name: (string) ($user->display_name ?? $user->username ?? 'there'),
            siteName: (string) config('app.name', 'NovFora'),
            url: route('forums.index'),
        ));
    }
}
