<?php
// SPDX-License-Identifier: Apache-2.0
use App\Admin\AdminCoOwnerService;
use App\Models\User;
use App\Permissions\Scope;
use App\Upgrade\ReleaseCoordinator;
use App\Upgrade\ReleaseRunState;
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

    public ?string $availableVersion = null;

    public bool $checkedReleases = false;

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function apply(UpgradeRunner $runner): void
    {
        $this->ensureAdmin();

        if (app(ReleaseRunState::class)->isActive()) {
            $this->message = 'A release upgrade is already in progress. Wait for it to finish before applying migrations.';
            $this->messageVariant = 'warn';

            return;
        }

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

    public function checkRelease(ReleaseCoordinator $releases): void
    {
        $releases->authorize($this->currentAdmin());

        try {
            $latest = $releases->check(true);
            $this->checkedReleases = true;
            $version = $latest['version'] ?? null;
            $this->availableVersion = is_string($version)
                && version_compare($version, (string) config('app.version'), '>') ? $version : null;
            $this->message = $this->availableVersion
                ? "Stable release {$this->availableVersion} is available. Its signature will be verified before installation."
                : 'No newer stable release with both ZIP and signature assets is available.';
            $this->messageVariant = 'info';
        } catch (Throwable $e) {
            report($e);
            $this->availableVersion = null;
            $this->checkedReleases = false;
            $this->message = 'Could not check stable releases. Try again shortly.';
            $this->messageVariant = 'danger';
        }
    }

    public function upgradeRelease(ReleaseCoordinator $releases): void
    {
        $actor = $this->currentAdmin();
        $releases->authorize($actor);

        try {
            $script = request()->server('SCRIPT_FILENAME');
            $resolved = is_string($script) && basename($script) === 'index.php' ? realpath($script) : false;
            $publicDirectory = $resolved !== false ? dirname($resolved) : public_path();
            $run = $releases->request($actor, $publicDirectory);
            $this->availableVersion = null;
            $this->message = 'Release '.($run['version'] ?? 'upgrade').' is queued. Cron will download and verify it, then show a brief maintenance page while applying it.';
            $this->messageVariant = 'success';
            // The preboot recovery route serves this same URL while the main app is unavailable.
            $this->redirect(rtrim((string) config('app.url'), '/').'/admin/system/upgrade');
        } catch (RuntimeException $e) {
            report($e);
            $this->message = $e->getMessage() === 'There is no newer signed stable release available.'
                ? 'No newer stable release with both ZIP and signature assets is available.'
                : 'Could not queue the release upgrade. Check the server log and try again.';
            $this->messageVariant = $e->getMessage() === 'There is no newer signed stable release available.' ? 'info' : 'danger';
        }
    }

    /** @return array{phase:string,version?:string} */
    public function releaseStatus(): array
    {
        $this->currentAdmin();

        try {
            $state = app(ReleaseRunState::class)->read();

            // Livewire public methods are callable actions. Never return private worker state (paths,
            // actor IDs, release URLs, backup locations, health probes) to the browser.
            $phase = $state['phase'] ?? 'idle';
            $safe = ['phase' => is_string($phase) && in_array($phase, [
                'idle', 'requested', 'downloading', 'snapshotting', 'applying', 'migrating',
                'checking', 'complete', 'failed', 'rolled_back', 'held',
            ], true) ? $phase : 'held'];
            if (is_string($state['version'] ?? null)
                && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $state['version']) === 1) {
                $safe['version'] = $state['version'];
            }

            return $safe;
        } catch (Throwable $e) {
            report($e);

            return ['phase' => 'held'];
        }
    }

    public function canManageReleases(): bool
    {
        $this->ensureAdmin();
        $user = auth()->user();

        return $user instanceof User && $user->two_factor_confirmed_at !== null
            && app(AdminCoOwnerService::class)->isCoOwner($user);
    }

    /** @return array{auto:bool, pending:list<string>, pendingCount:int, upgrading:bool, stuck:bool, last:?array} */
    public function status(): array
    {
        $this->ensureAdmin();
        $schema = app(SchemaState::class);

        $pending = [];
        try {
            $pending = $schema->pendingMigrationNames();
        } catch (Throwable) {
            // DB unreachable — show zero rather than erroring the panel.
        }

        return [
            'auto' => (bool) config('novfora.upgrade.auto', true),
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
        // Defense in depth: re-assert the staff-2FA gate here too. The route group enforces it at page load,
        // but Livewire actions reach the component via livewire/update (no route middleware), so — like the
        // admin.access check above — the destructive apply() action self-guards rather than trusting the route.
        abort_if($user->isStaff() && $user->two_factor_confirmed_at === null, 403);
    }

    private function currentAdmin(): User
    {
        $this->ensureAdmin();

        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
};
?>

@php($s = $this->status())
@php($release = $this->releaseStatus())
@php($releasePhase = $release['phase'] ?? 'idle')
@php($releaseActive = !in_array($releasePhase, ['idle', 'complete', 'failed', 'rolled_back'], true))

<div class="space-y-5">
    <x-ui.card>
        <div @if ($releaseActive) wire:poll.10s @endif class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-ink">Core release</h2>
                    <p class="text-sm text-ink-muted">Installed version: <strong>{{ config('app.version') }}</strong></p>
                </div>
                @if ($this->canManageReleases())
                    <x-ui.button type="button" size="sm" variant="subtle" wire:click="checkRelease" wire:loading.attr="disabled" wire:target="checkRelease">
                        Check for updates
                    </x-ui.button>
                @endif
            </div>

            @if ($releasePhase === 'failed' || $releasePhase === 'rolled_back' || $releasePhase === 'held')
                <x-ui.alert variant="danger">
                    The release upgrade needs attention. The site may remain in maintenance; inspect the server log and recovery state before retrying.
                </x-ui.alert>
            @elseif ($releaseActive)
                <x-ui.alert variant="info">
                    Release {{ $release['version'] ?? '' }}: {{ ucfirst(str_replace('_', ' ', $releasePhase)) }}.
                    Cron continues the upgrade. The site may show a brief maintenance page.
                </x-ui.alert>
            @elseif ($releasePhase === 'complete')
                <x-ui.alert variant="success">Release {{ $release['version'] ?? '' }} completed.</x-ui.alert>
            @endif

            @if (!$releaseActive && $this->canManageReleases())
                <div class="flex flex-wrap items-center gap-3">
                    @if ($availableVersion)
                        <span class="text-sm text-ink-muted">Newer stable release: <strong>{{ $availableVersion }}</strong></span>
                    @endif
                    <x-ui.button type="button" wire:click="upgradeRelease" wire:loading.attr="disabled" wire:target="upgradeRelease"
                                 wire:confirm="Check and upgrade to the latest signed stable release? Cron downloads and verifies it, takes a backup, and enters brief maintenance while applying it.">
                        Upgrade to latest stable
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.card>

    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-medium text-ink">Automatic upgrades</span>
        @if ($s['auto'])
            <x-ui.badge variant="success">On</x-ui.badge>
            <span class="text-sm text-ink-muted">New releases migrate themselves via cron — no action needed.</span>
        @else
            <x-ui.badge variant="neutral">Off · manual</x-ui.badge>
            <span class="text-sm text-ink-muted">You apply pending migrations yourself (here, or via <code class="font-mono text-xs">php artisan novfora:upgrade</code>).</span>
        @endif
    </div>

    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    @if ($s['stuck'])
        <x-ui.alert variant="danger">
            <strong>An upgrade is held for attention.</strong> The site is in maintenance and will not retry
            automatically. Re-upload the previous release (the gate clears within a cron tick), or restore the
            pre-upgrade backup with <code class="font-mono text-xs">php artisan novfora:restore</code>. See
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

        @if ($s['pendingCount'] > 0 && !$releaseActive)
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
