<?php
// SPDX-License-Identifier: Apache-2.0
use App\Backup\BackupException;
use App\Backup\BackupService;
use App\Backup\RestoreRunner;
use App\Backup\RestoreState;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;

/**
 * Admin → System → Backups (M5 + RH-11 restore). Run a backup, download/delete an archive, or RESTORE one
 * (no SSH). Authorization is enforced IN the component (mount + every action), not only on the route —
 * Livewire actions reach the component via the livewire/update endpoint, which does not carry the
 * admin/system route middleware. Download and delete are path-safe: the name is reduced to a basename and
 * must match the exact `hearth-*.zip` pattern inside the configured backup directory, so neither can touch
 * an arbitrary file.
 *
 * RESTORE is destructive (it overwrites the live database + uploaded files), so it is guarded harder than
 * the other actions: admin.access PLUS staff-2FA (self-guarded here as well as on the route, mirroring the
 * RH-10 Upgrade panel) AND a typed confirmation — the operator must type the backup's exact name. The
 * action only RECORDS the request (App\Backup\RestoreRunner::request) after re-validating the archive; the
 * single cron line performs the restore behind the branded maintenance window (RestoreRunner). The operator
 * is then redirected into that self-refreshing maintenance page and watches it via /health — exactly like a
 * no-SSH upgrade.
 */
new class extends Component
{
    public ?string $message = null;

    public string $messageVariant = 'info';

    /** The archive the operator is confirming a restore for (null = not confirming), + their typed input. */
    public ?string $confirming = null;

    public string $typedName = '';

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
            $this->messageVariant = 'info';
        } catch (\Throwable $e) {
            $this->message = 'Backup failed: '.$e->getMessage();
            $this->messageVariant = 'danger';
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
        if ($this->confirming === $name) {
            $this->cancelRestore(); // don't leave a confirm box open for a row we just deleted
        }
        if ($path = $this->resolve($backups, $name)) {
            @unlink($path);
            $this->message = 'Deleted '.basename($path).'.';
            $this->messageVariant = 'info';
        }
    }

    /** Open the typed-confirmation panel for one archive (UI state only — nothing destructive yet). */
    public function startRestore(BackupService $backups, string $name): void
    {
        $this->ensureCanRestore();
        $this->message = null;
        if ($this->resolve($backups, $name) === null) {
            $this->message = 'That backup could not be found.';
            $this->messageVariant = 'danger';

            return;
        }
        $this->confirming = basename($name);
        $this->typedName = '';
    }

    public function cancelRestore(): void
    {
        $this->confirming = null;
        $this->typedName = '';
    }

    /** Record the restore request (the cron line performs it). Requires the typed name to match exactly. */
    public function confirmRestore(RestoreRunner $runner)
    {
        $this->ensureCanRestore();

        $name = $this->confirming;
        if ($name === null) {
            return;
        }

        // The typed-confirmation gate — authoritative on the server, not only the disabled button.
        if (trim($this->typedName) !== $name) {
            $this->message = 'The name you typed does not match — restore not started.';
            $this->messageVariant = 'danger';

            return;
        }

        try {
            $user = auth()->user();
            $runner->request($name, $user?->id, $user?->name);
        } catch (BackupException $e) {
            $this->confirming = null;
            $this->typedName = '';
            $this->message = 'Restore not started: '.$e->getMessage();
            $this->messageVariant = 'danger';

            return;
        }

        // The restore is now requested → the maintenance gate engages on the next request. Send the operator
        // to the self-refreshing maintenance page; they'll be signed out by the restore and sign back in when
        // it completes (watchable meanwhile via /health → "restore").
        return redirect('/');
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
    }

    /** Stricter gate for the destructive restore: admin.access AND a confirmed second factor for staff. */
    private function ensureCanRestore(): void
    {
        $this->ensureAdmin();
        $user = auth()->user();
        abort_if($user instanceof User && $user->isStaff() && $user->two_factor_confirmed_at === null, 403);
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

    /** The last restore's outcome, for a compact status line (RestoreState; null = never restored here). */
    public function lastRestore(): ?array
    {
        return app(RestoreState::class)->lastRun();
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
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    @php($last = $this->lastRestore())
    @if ($last)
        <p class="text-xs text-ink-subtle">
            Last restore:
            <strong class="text-ink-muted">{{ ($last['result'] ?? '—') === 'success' ? 'Succeeded' : 'Failed' }}</strong>
            @if (!empty($last['archive'])) · <code class="font-mono">{{ $last['archive'] }}</code> @endif
            @if (!empty($last['at'])) · {{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }} @endif
        </p>
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
                            <x-ui.button type="button" variant="ghost" size="sm"
                                         wire:click="startRestore('{{ $item['name'] }}')">Restore</x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm"
                                         wire:click="delete('{{ $item['name'] }}')"
                                         wire:confirm="Delete {{ $item['name'] }}?">Delete</x-ui.button>
                        </div>
                    </div>

                    @if ($confirming === $item['name'])
                        <div class="px-4 py-4 sm:px-5 bg-surface-sunken">
                            <x-ui.alert variant="danger" class="mb-3">
                                <strong>This overwrites the current database and uploaded files</strong> with the
                                backup <code class="font-mono">{{ $item['name'] }}</code>, taken
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($item['created'])->toDayDateTimeString() }}
                                ({{ $this->human($item['size']) }}). Everything posted since then is lost. A pre-restore
                                safety snapshot of the <em>current</em> state is taken first, so you can roll back.
                            </x-ui.alert>

                            <label class="block text-sm text-ink-muted mb-1.5">
                                Type the backup name to confirm: <code class="font-mono text-ink">{{ $item['name'] }}</code>
                            </label>
                            <input type="text"
                                   wire:model.live.debounce.250ms="typedName"
                                   autocomplete="off" autocapitalize="off" spellcheck="false"
                                   class="w-full max-w-md rounded-md border border-line bg-surface px-3 py-2 font-mono text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                                   placeholder="{{ $item['name'] }}">

                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <x-ui.button type="button" variant="danger"
                                             wire:click="confirmRestore"
                                             wire:loading.attr="disabled" wire:target="confirmRestore"
                                             :disabled="trim($typedName) !== $item['name']">
                                    <span wire:loading.remove wire:target="confirmRestore">Restore this backup</span>
                                    <span wire:loading wire:target="confirmRestore">Starting…</span>
                                </x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="cancelRestore">Cancel</x-ui.button>
                            </div>
                            <p class="mt-2 text-xs text-ink-subtle">
                                The site shows a brief maintenance page while it restores (within ~1 minute). You'll be
                                signed out — sign back in when it's done. Watch progress at <code class="font-mono">/health</code>.
                            </p>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
