<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Registry\FeedSigner;
use Illuminate\Console\Command;

/**
 * Ops-side registry feed signing (Phase 4C, ADR-0113). Generates the registry root keypair, or signs a
 * feed.json into a detached feed.json.sig with the root secret key. The forum never holds the secret key —
 * only the PINNED public key ships in config; this runs where the feed is published.
 *
 *   php artisan novfora:registry:sign --keygen
 *   NOVFORA_REGISTRY_SECRET_KEY=<b64> php artisan novfora:registry:sign registry.json
 */
class RegistrySignCommand extends Command
{
    protected $signature = 'novfora:registry:sign {feed? : path to feed.json} {--keygen : print a fresh root keypair}';

    protected $description = 'Sign the NovFora Registry feed (or generate the root keypair)';

    public function handle(): int
    {
        if (! FeedSigner::available()) {
            $this->error('ext-sodium is required.');

            return self::FAILURE;
        }

        if ($this->option('keygen')) {
            $pair = sodium_crypto_sign_keypair();
            $this->line('Public (pin this in NOVFORA_REGISTRY_ROOT_KEY):');
            $this->line(base64_encode(sodium_crypto_sign_publickey($pair)));
            $this->line('Secret (keep OFFLINE; sign feeds with it):');
            $this->line(base64_encode(sodium_crypto_sign_secretkey($pair)));

            return self::SUCCESS;
        }

        $feed = (string) $this->argument('feed');
        if ($feed === '' || ! is_file($feed)) {
            $this->error('Pass a path to feed.json, or --keygen.');

            return self::FAILURE;
        }
        // Read the root secret straight from the process env — this ops CLI runs where the feed is
        // published, never in the app (getenv, not env(), so it works regardless of config caching).
        $secret = (string) getenv('NOVFORA_REGISTRY_SECRET_KEY');
        if ($secret === '') {
            $this->error('Set NOVFORA_REGISTRY_SECRET_KEY (base64 root secret key).');

            return self::FAILURE;
        }

        $sig = FeedSigner::sign((string) file_get_contents($feed), $secret);
        file_put_contents($feed.'.sig', $sig);
        $this->info('Wrote '.$feed.'.sig');

        return self::SUCCESS;
    }
}
