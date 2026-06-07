<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Upgrade\SchemaState;
use App\Upgrade\UpgradeRunner;
use Livewire\Component;

/**
 * Admin → System → Upgrade (RH-10). Shows the no-SSH upgrade state (pending migrations, in-progress,
 * held-for-operator) and, in MANUAL mode, an "Apply pending migrations" action — the same backup-first
 * pipeline the cron tick uses, human-triggered. Authorization is enforced IN the component (mount + the
 * action), like the Backups panel, because Livewire actions reach the component via the livewire/update
 * endpoint, which does not carry the admin/system route middleware. The 2FA requirement is enforced by the
 * admin/system route group at page load. This component reads only the SchemaState cache + the migrator —
 * never a schema-version-sensitive column — so it renders even when the schema is briefly behind the code.
 */
new class extends Component
{
    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function apply(UpgradeRunner $runner): void
    {
        $this->ensureAdmin();

        $result = $runner->runManual();

        if ($result->isSuccess()) {
            $this->message = "Applied {$result->migrationsApplied} migration(s) in {$result->durationMs} ms."
                .($result->backup ? ' Pre-upgrade backup: '.$result->backup.'.' : '');
            $this->messageVariant = 'success';
        } elseif ($result->isSkipped()) {
            $this->message = 'Nothing to apply ('.$result->reason.').';
            $this->messageVariant = 'info';
        } else {
            $this->message = "Upgrade failed during the {$result->stage} step. The site is held in maintenance; "
                .'restore the pre-upgrade backup'.($result->backup ? ' ('.$result->backup.')' : '')
                .' or re-upload the previous release. See getting-started §5.';
            $this->messageVariant = 'danger';
        }
    }

    /** @return array{auto:bool, pending:list<string>, pendingCount:int, upgrading:bool, stuck:bool, last:?array} */
    public function status(): array
    {
        $schema = app(SchemaState::class);

        $pending = [];
        try {
            $pending = $schema->pendingMigrationNames();
        } catch (\Throwable) {
            // DB unreachable — show zero rather than erroring the panel.
        }

        return [
            'auto' => (bool) config('hearth.upgrade.auto', true),
            'pending' => $pending,
            'pendingCount' => count($pending),
            'upgrading' => $schema->isUpgrading(),
            'stuck' => $schema->isStuck(),
            'last' => $schema->lastRun(),
        ];
    }

    private function ensureAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->canDo('admin.access', Scope::global()), 403);
    }
};
?>

@php($s = $this->status())

<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-medium text-ink">Automatic upgrades</span>
        @if ($s['auto'])
            <x-ui.badge variant="success">On</x-ui.badge>
            <span class="text-sm text-ink-muted">New releases migrate themselves via cron — no action needed.</span>
        @else
            <x-ui.badge variant="neutral">Off · manual</x-ui.badge>
            <span class="text-sm text-ink-muted">You apply pending migrations yourself (here, or via <code class="font-mono text-xs">php artisan hearth:upgrade</code>).</span>
        @endif
    </div>

    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    @if ($s['stuck'])
        <x-ui.alert variant="danger">
            <strong>An upgrade is held for attention.</strong> The site is in maintenance and will not retry
            automatically. Re-upload the previous release (the gate clears within a cron tick), or restore the
            pre-upgrade backup with <code class="font-mono text-xs">php artisan hearth:restore</code>. See
            getting-started §5.
        </x-ui.alert>
    @endif

    <x-ui.card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Pending migrations</div>
                <div class="mt-1 text-2xl font-semibold text-ink nums">{{ $s['pendingCount'] }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Status</div>
                <div class="mt-1.5">
                    @if ($s['upgrading'])
                        <x-ui.badge variant="warn">Upgrading…</x-ui.badge>
                    @elseif ($s['stuck'])
                        <x-ui.badge variant="danger">Held</x-ui.badge>
                    @elseif ($s['pendingCount'] > 0)
                        <x-ui.badge variant="accent">Behind</x-ui.badge>
                    @else
                        <x-ui.badge variant="success">Up to date</x-ui.badge>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Last upgrade</div>
                <div class="mt-1.5 text-sm text-ink-muted">
                    @if ($s['last'])
                        {{ ($s['last']['result'] ?? '—') === 'success' ? 'Succeeded' : 'Failed' }}
                        @if (!empty($s['last']['at']))
                            · {{ \Illuminate\Support\Carbon::parse($s['last']['at'])->diffForHumans() }}
                        @endif
                        @if (($s['last']['result'] ?? null) === 'success' && isset($s['last']['migrations']))
                            · {{ $s['last']['migrations'] }} migration(s)
                        @endif
                    @else
                        Never run
                    @endif
                </div>
            </div>
        </div>

        @if ($s['pendingCount'] > 0)
            <div class="mt-5 border-t border-line pt-4">
                <details>
                    <summary class="cursor-pointer text-sm font-medium text-ink-muted">Show {{ $s['pendingCount'] }} pending migration(s)</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach ($s['pending'] as $name)
                            <li><code class="font-mono text-xs text-ink-muted break-all">{{ $name }}</code></li>
                        @endforeach
                    </ul>
                </details>

                <div class="mt-4">
                    <x-ui.button type="button"
                                 wire:click="apply"
                                 wire:loading.attr="disabled" wire:target="apply"
                                 wire:confirm="Apply {{ $s['pendingCount'] }} pending migration(s)? A pre-upgrade backup is taken first; the site shows a brief maintenance page while it runs.">
                        <span wire:loading.remove wire:target="apply">Apply pending migrations</span>
                        <span wire:loading wire:target="apply">Applying…</span>
                    </x-ui.button>
                    @if ($s['auto'])
                        <p class="mt-2 text-xs text-ink-subtle">Automatic mode normally applies these within a minute; this button forces it now.</p>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.card>
</div>
