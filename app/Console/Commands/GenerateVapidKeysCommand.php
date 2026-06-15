<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Settings\Settings;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates and stores the site's VAPID keypair for Web Push (Phase 4 · M3.2). The public key is served to
 * browsers to subscribe; the private key (stored encrypted) signs the push JWT. Idempotent-safe: refuses to
 * overwrite an existing keypair unless --force (rotating the keys invalidates every existing subscription).
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'novfora:push:vapid {--force : Overwrite an existing keypair (invalidates all current subscriptions)}';

    protected $description = 'Generate and store the VAPID keypair used for Web Push notifications';

    public function handle(Settings $settings): int
    {
        if ($settings->secretIsSet('push.vapid_private_key') && ! $this->option('force')) {
            $this->warn('A VAPID keypair already exists. Re-run with --force to rotate it (this invalidates every current push subscription).');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();

        $settings->set('push.vapid_public_key', $keys['publicKey']);
        $settings->set('push.vapid_private_key', $keys['privateKey']);
        if ($settings->string('push.vapid_subject') === '') {
            $settings->set('push.vapid_subject', (string) config('app.url', 'https://localhost'));
        }

        $this->info('VAPID keypair generated and stored. Web Push is now configured (users still opt in per device).');

        return self::SUCCESS;
    }
}
