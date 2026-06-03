<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Install\PublicStorageLinker;
use Illuminate\Console\Command;

/**
 * `php artisan hearth:storage:publish` — make uploaded public files reachable under public/storage. Prefers
 * a real symlink; on hosts that forbid symlinks it writes (and refreshes) a copy mirror instead. The single
 * cron line keeps the mirror current, but operators can run this by hand after bulk changes.
 */
class StoragePublishCommand extends Command
{
    protected $signature = 'hearth:storage:publish';

    protected $description = 'Publish public uploads to public/storage (symlink, or a copy mirror where symlinks are disabled).';

    public function handle(PublicStorageLinker $linker): int
    {
        return match ($linker->publish()) {
            'symlink' => $this->ok('public/storage is a live symlink.'),
            'copy' => $this->ok('Symlinks are unavailable — public/storage is a refreshed copy mirror.'),
            default => $this->failure(),
        };
    }

    private function ok(string $message): int
    {
        $this->components->info($message);

        return self::SUCCESS;
    }

    private function failure(): int
    {
        $this->components->error('Could not publish public/storage (symlink disabled and the copy fallback failed).');

        return self::FAILURE;
    }
}
