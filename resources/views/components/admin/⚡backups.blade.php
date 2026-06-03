<?php
// SPDX-License-Identifier: Apache-2.0
use App\Backup\BackupService;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * Admin → System → Backups (M5). Run a backup, download or delete an archive. Authorization is enforced
 * IN the component (mount + every action), not only on the route — Livewire actions reach the component
 * via the livewire/update endpoint, which does not carry the admin/system route middleware. Download and
 * delete are path-safe: the name is reduced to a basename and must match the exact `hearth-*.zip` pattern
 * inside the configured backup directory, so neither can touch an arbitrary file.
 */
new class extends Component
{
    public ?string $message = null;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function runBackup(BackupService $backups): void
    {
        $this->ensureAdmin();
        try {
            $result = $backups->create();
            $this->message = 'Backup created: '.$result->name().' ('.$this->human($result->sizeBytes).').';
        } catch (\Throwable $e) {
            $this->message = 'Backup failed: '.$e->getMessage();
        }
    }

    public function download(BackupService $backups, string $name)
    {
        $this->ensureAdmin();
        $path = $this->resolve($backups, $name);
        abort_if($path === null, 404);

        return response()->download($path);
    }

    public function delete(BackupService $backups, string $name): void
    {
        $this->ensureAdmin();
        if ($path = $this->resolve($backups, $name)) {
            @unlink($path);
            $this->message = 'Deleted '.basename($path).'.';
        }
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
    }

    /** Resolve a user-supplied name to a real archive path, or null if it isn't a valid backup. */
    private function resolve(BackupService $backups, string $name): ?string
    {
        $name = basename($name);
        if (! preg_match('/^hearth-\d{8}-\d{6}\.zip$/', $name)) {
            return null;
        }
        $path = $backups->destination().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).' '.$units[$i];
    }
};
?>

<div style="font-family:system-ui,sans-serif">
    <p>
        <button type="button" wire:click="runBackup" wire:loading.attr="disabled" wire:target="runBackup"
                style="padding:.5rem 1rem;border:1px solid #2d2a6b;background:#2d2a6b;color:#fff;border-radius:8px;cursor:pointer">
            <span wire:loading.remove wire:target="runBackup">Create backup now</span>
            <span wire:loading wire:target="runBackup">Backing up…</span>
        </button>
    </p>

    @if ($message)
        <p style="background:#f0f4ff;border:1px solid #d6e0ff;border-radius:8px;padding:.6rem .8rem">{{ $message }}</p>
    @endif

    @php($items = app(BackupService::class)->list())
    @if (empty($items))
        <p style="color:#777">No backups yet. Create one above, or wait for the scheduled run.</p>
    @else
        <table cellpadding="7" cellspacing="0" style="border-collapse:collapse;width:100%">
            <thead>
                <tr style="background:#f4f4f5;text-align:left">
                    <th style="border:1px solid #ddd">Archive</th>
                    <th style="border:1px solid #ddd">Size</th>
                    <th style="border:1px solid #ddd">Created</th>
                    <th style="border:1px solid #ddd">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td style="border:1px solid #ddd"><code>{{ $item['name'] }}</code></td>
                        <td style="border:1px solid #ddd">{{ $this->human($item['size']) }}</td>
                        <td style="border:1px solid #ddd">{{ \Illuminate\Support\Carbon::createFromTimestamp($item['created'])->toDayDateTimeString() }}</td>
                        <td style="border:1px solid #ddd">
                            <button type="button" wire:click="download('{{ $item['name'] }}')" style="cursor:pointer">Download</button>
                            <button type="button" wire:click="delete('{{ $item['name'] }}')"
                                    wire:confirm="Delete {{ $item['name'] }}?" style="cursor:pointer;color:#b3261e">Delete</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
