<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Packaging\PackageException;
use App\Modules\Packaging\PackageSignature;
use Illuminate\Console\Command;

/**
 * Author-side signing helper for the install-from-zip trust gate (U17, ADR-0104). The SERVER only ever
 * verifies; this command is the recipe a plugin author runs to (a) generate an ed25519 keypair whose PUBLIC
 * half an operator adds to their trusted keys, and (b) write a `module.sig` into a package directory before
 * zipping it. The secret key never touches the server.
 */
final class ModulePackageSignCommand extends Command
{
    protected $signature = 'novfora:module:sign
                            {dir? : the module package directory to sign (writes module.sig)}
                            {--key= : base64 ed25519 secret key}
                            {--keygen : generate and print a new ed25519 keypair, then exit}';

    protected $description = 'Generate an ed25519 keypair or sign a module package directory (module.sig)';

    public function handle(): int
    {
        if (! PackageSignature::available()) {
            $this->error('ext-sodium is not available in this PHP runtime; cannot sign.');

            return self::FAILURE;
        }

        if ($this->option('keygen')) {
            $pair = sodium_crypto_sign_keypair();
            $public = base64_encode(sodium_crypto_sign_publickey($pair));
            $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
            $this->info('ed25519 keypair generated.');
            $this->line('PUBLIC  (add this to the ACP trusted keys): '.$public);
            $this->line('SECRET  (keep private — signs your packages): '.$secret);

            return self::SUCCESS;
        }

        $dir = (string) $this->argument('dir');
        $key = (string) $this->option('key');
        if ($dir === '' || ! is_dir($dir)) {
            $this->error('Pass a package directory to sign, or --keygen to make a key.');

            return self::FAILURE;
        }
        if ($key === '') {
            $this->error('Pass --key=<base64 secret key> to sign (or --keygen to make one).');

            return self::FAILURE;
        }

        try {
            $sig = PackageSignature::sign($dir, $key);
        } catch (PackageException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = rtrim($dir, '/\\').'/'.PackageSignature::SIGNATURE_FILE;
        file_put_contents($path, $sig.PHP_EOL);
        $this->info('Wrote '.$path.' — zip the directory (module.json at the archive root) and upload it.');

        return self::SUCCESS;
    }
}
