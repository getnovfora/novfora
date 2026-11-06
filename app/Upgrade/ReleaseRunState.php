<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

use RuntimeException;

/** Private, atomic state and an OS-held lock; usable by the frozen recovery worker without Laravel. */
class ReleaseRunState
{
    public readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('framework/release-upgrade');
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        $path = $this->directory.'/state.json';
        if (! file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read upgrade state. Keep the site in maintenance.');
        }
        $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($state) || ! isset($state['phase'])) {
            throw new RuntimeException('Invalid upgrade state. Keep the site in maintenance.');
        }

        return $state;
    }

    /** Caller must hold locked(). @param array<string,mixed> $state */
    public function write(array $state): void
    {
        $this->prepareDirectory();
        $path = $this->directory.'/state.json';
        $temp = tempnam($this->directory, '.state-');
        if ($temp === false) {
            throw new RuntimeException('Cannot stage upgrade state.');
        }
        try {
            chmod($temp, 0600);
            $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $handle = fopen($temp, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Cannot open upgrade state.');
            }
            try {
                if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException('Cannot persist upgrade state.');
                }
                if (function_exists('fsync') && ! fsync($handle)) {
                    throw new RuntimeException('Cannot synchronize upgrade state.');
                }
            } finally {
                fclose($handle);
            }
            if (! rename($temp, $path)) {
                throw new RuntimeException('Cannot publish upgrade state.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /** Kernel releases the lock even when the process is killed. Never reclaim by age. */
    public function locked(callable $operation): mixed
    {
        $this->prepareDirectory();
        $lock = fopen($this->directory.'/run.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open upgrade lock.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another upgrade operation is running.');
            }
            try {
                return $operation();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    public function isActive(): bool
    {
        return ! in_array($this->read()['phase'] ?? 'idle', ['idle', 'complete', 'failed', 'rolled_back'], true);
    }

    public function prepareDirectory(): void
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('The upgrade directory must not be a symbolic link.');
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the private upgrade directory.');
        }
        chmod($this->directory, 0700);
        if (file_put_contents($this->directory.'/.htaccess', "Require all denied\n") === false) {
            throw new RuntimeException('Cannot protect the upgrade directory.');
        }
    }
}
