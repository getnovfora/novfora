<?php
// SPDX-License-Identifier: Apache-2.0
use App\Jobs\ReindexSearch;
use App\Models\User;
use App\Permissions\Scope;
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use App\Settings\Settings;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Admin → Settings → Search (Phase 4 · M4.1). Chooses the Scout driver and the Meilisearch connection.
 * `database` is the baseline (no external service); switching to `meilisearch` is refused unless the host
 * is reachable, and the runtime degrades back to the database engine automatically if it later goes away
 * (App\Search\SearchService). The Meilisearch key is stored ENCRYPTED. A reindex is a queued job so it is
 * drained by the baseline cron tick and never blocks this request.
 */
new class extends Component
{
    public string $driver = 'database';

    public string $host = '';

    public string $key = ''; // never pre-filled — secret

    public bool $keySet = false;

    public ?string $saved = null;

    public ?string $error = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->driver = $settings->string('search.driver') ?: 'database';
        $this->host = $settings->string('search.meilisearch_host');
        $this->keySet = $settings->secretIsSet('search.meilisearch_key');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->saved = $this->error = null;

        $data = $this->validate([
            'driver' => ['required', 'in:database,meilisearch'],
            'host' => ['nullable', 'string', 'max:255', 'url'],
            'key' => ['nullable', 'string', 'max:255'],
        ]);

        // Never let an admin strand search on an unreachable engine: prove the host responds before switching.
        if ($data['driver'] === 'meilisearch' && ! $this->reachable((string) $data['host'])) {
            $this->error = 'Meilisearch did not respond at that host. Search was left on the database driver.';

            return;
        }

        $settings->set('search.meilisearch_host', $data['host'] ?? '');
        $settings->set('search.meilisearch_key', $data['key']); // blank ⇒ keep existing
        $settings->set('search.driver', $data['driver']);

        $this->key = '';
        $this->keySet = $settings->secretIsSet('search.meilisearch_key');
        $this->saved = $data['driver'] === 'meilisearch'
            ? 'Saved. Enhanced search is active — run a reindex to populate Meilisearch.'
            : 'Saved. Search is on the baseline database engine.';
    }

    /** Queue a full reindex (cron-drained). No-op on the database driver. */
    public function reindex(): void
    {
        $this->ensureAdmin();
        ReindexSearch::dispatch();
        $this->saved = 'Reindex queued — it will run on the next scheduler tick.';
    }

    /** Live engine health for the panel (configured + reachable + latency). Never throws. */
    public function health(): array
    {
        $svc = app(ServiceTier::class)->refresh()->services['meilisearch'] ?? null;

        return [
            'enhanced' => app(ServiceTier::class)->isEnhanced(Capability::Search),
            'configured' => $svc?->configured ?? false,
            'reachable' => $svc?->reachable ?? false,
            'latency' => $svc?->latencyMs,
        ];
    }

    /** Mirror MeilisearchProbe::check() against the ENTERED host (pre-save), failing closed. */
    private function reachable(string $host): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }

        try {
            return Http::timeout(2)->connectTimeout(1)->get(rtrim($host, '/').'/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6" x-data>
    <form wire:submit="save" class="space-y-5">
        @if ($saved)
            <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
        @endif
        @if ($error)
            <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
        @endif

        <div id="setting-search-driver">
            <x-ui.select label="Search driver" name="driver" wire:model.live="driver"
                         hint="Database is the baseline (works on any host). Meilisearch is an opt-in upgrade for typo-tolerant, faster, more relevant search.">
                <option value="database">Database (baseline)</option>
                <option value="meilisearch">Meilisearch (enhanced)</option>
            </x-ui.select>
        </div>

        @if ($driver === 'meilisearch')
            <fieldset class="grid gap-5 border-t border-line pt-5 sm:grid-cols-2">
                <legend class="sr-only">Meilisearch connection</legend>
                <div id="setting-search-meilisearch-host">
                    <x-ui.input label="Meilisearch host" name="host" wire:model="host"
                                placeholder="http://127.0.0.1:7700"
                                hint="The board refuses to switch unless this host responds." />
                </div>
                <div id="setting-search-meilisearch-key">
                    <x-ui.input label="Meilisearch API key" name="key" type="password" wire:model="key"
                                autocomplete="new-password"
                                :placeholder="$keySet ? '•••••• (leave blank to keep)' : ''"
                                hint="Stored encrypted." />
                </div>
            </fieldset>
        @endif

        <div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>

    @php($status = $this->health())
    <x-ui.card>
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-ink">Engine status</h2>
                <p class="mt-1 text-sm text-ink-muted">
                    @if ($status['enhanced'] && $status['reachable'])
                        <span class="font-medium text-emerald-600">Enhanced — Meilisearch reachable</span>
                        @if ($status['latency'] !== null) <span class="text-ink-subtle">({{ $status['latency'] }} ms)</span> @endif
                    @elseif ($status['enhanced'])
                        <span class="font-medium text-amber-600">Meilisearch configured but unreachable</span> — search is degrading to the database engine.
                    @else
                        <span class="font-medium text-ink">Baseline — database engine.</span> Works everywhere; no external service required.
                    @endif
                </p>
            </div>
            @if ($status['enhanced'])
                <x-ui.button type="button" variant="subtle" wire:click="reindex" wire:loading.attr="disabled" wire:target="reindex">
                    <span wire:loading.remove wire:target="reindex">Reindex now</span>
                    <span wire:loading wire:target="reindex">Queuing…</span>
                </x-ui.button>
            @endif
        </div>
    </x-ui.card>
</div>
