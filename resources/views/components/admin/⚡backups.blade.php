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

<div class="space-y-5">
    <div>
        <x-ui.button type="button" wire:click="runBackup" wire:loading.attr="disabled" wire:target="runBackup">
            <span wire:loading.remove wire:target="runBackup">Create backup now</span>
            <span wire:loading wire:target="runBackup">Backing up…</span>
        </x-ui.button>
    </div>

    @if ($message)
        <x-ui.alert variant="info">{{ $message }}</x-ui.alert>
    @endif

    @php($items = app(BackupService::class)->list())
    @if (empty($items))
        <x-ui.empty title="No backups yet" :icon="'<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.75\' stroke-linecap=\'round\' stroke-linejoin=\'round\' class=\'h-6 w-6\'><path d=\'M3 13h5l1.5 3h5L16 13h5\'/><path d=\'M5 5h14l2 8v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5z\'/></svg>'">
            Create one above, or wait for the scheduled run.
        </x-ui.empty>
    @else
        <x-ui.card flush>
            <div class="hidden sm:grid grid-cols-[2fr_auto_2fr_auto] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                <span>Archive</span>
                <span>Size</span>
                <span>Created</span>
                <span class="text-right">Actions</span>
            </div>
            <div class="divide-y divide-line">
                @foreach ($items as $item)
                    <div class="grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[2fr_auto_2fr_auto] sm:items-center sm:gap-3 sm:px-5">
                        <span class="text-ink break-all"><code class="font-mono text-sm">{{ $item['name'] }}</code></span>
                        <span class="text-sm text-ink-muted nums">{{ $this->human($item['size']) }}</span>
                        <span class="text-sm text-ink-muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($item['created'])->toDayDateTimeString() }}</span>
                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="download('{{ $item['name'] }}')">Download</x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm"
                                         wire:click="delete('{{ $item['name'] }}')"
                                         wire:confirm="Delete {{ $item['name'] }}?">Delete</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
