<?php
// SPDX-License-Identifier: Apache-2.0
use App\Deliverability\DeliverabilityManager;
use App\Deliverability\Suppressor;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Permissions\Scope;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin → System → Email suppressions (Spike P2). Lists the deliverability suppression list (bounce /
 * complaint / manual) and lets an admin add or remove an entry by hand — the manual floor that works on the
 * baseline tier with no provider. Shows which ingestion path is currently active (webhook / imap / manual)
 * and whether the pipeline is dormant. Self-guards like the other admin SFCs.
 */
new class extends Component
{
    use WithPagination;

    public string $newEmail = '';

    public ?string $flash = null;

    public string $flashVariant = 'success';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function add(): void
    {
        $this->ensureAdmin();
        $data = $this->validate(['newEmail' => ['required', 'email', 'max:255']]);

        // Resolve the service inside the action (not via method-injection) — Livewire mixing a container
        // class with trailing action args is the exact footgun that shipped a 500 in ACP v1.1.
        $added = app(Suppressor::class)->suppress($data['newEmail'], 'manual');
        $this->newEmail = '';
        $this->resetPage();
        $this->flash = $added ? 'Address suppressed.' : 'That address was already suppressed.';
        $this->flashVariant = $added ? 'success' : 'info';
    }

    public function remove(string $email): void
    {
        $this->ensureAdmin();
        $removed = app(Suppressor::class)->unsuppress($email);
        $this->resetPage();
        $this->flash = $removed ? 'Address un-suppressed — it can receive mail again.' : 'Nothing to remove.';
        $this->flashVariant = $removed ? 'success' : 'info';
    }

    public function entries()
    {
        $this->ensureAdmin();

        return EmailSuppression::query()->latest('id')->paginate(25);
    }

    public function activePath(): string
    {
        return app(DeliverabilityManager::class)->activePath();
    }

    public function dormant(): bool
    {
        return ! (bool) config('hearth.deliverability.enabled');
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-4">
    @if ($flash)
        <x-ui.alert :variant="$flashVariant">{{ $flash }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-ink-muted">Active ingestion path:</span>
            <x-ui.badge>{{ ucfirst($this->activePath()) }}</x-ui.badge>
            @if ($this->dormant())
                <span class="text-ink-subtle">— the digest/bounce pipeline is dormant; the manual list below still applies at send time.</span>
            @endif
        </div>

        <form wire:submit="add" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <x-ui.input label="Suppress an address" name="newEmail" type="email" wire:model="newEmail"
                            placeholder="user@example.com" hint="Stops all future mail to this address." />
            </div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="add">
                <span wire:loading.remove wire:target="add">Suppress</span>
                <span wire:loading wire:target="add">Saving…</span>
            </x-ui.button>
        </form>
    </x-ui.card>

    @php($entries = $this->entries())
    <x-ui.card flush>
        <div class="hidden sm:grid grid-cols-[1fr_8rem_10rem_6rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
            <span>Address</span>
            <span>Reason</span>
            <span class="text-right">Suppressed</span>
            <span class="text-right">Action</span>
        </div>
        <div class="divide-y divide-line">
            @forelse ($entries as $entry)
                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-[1fr_8rem_10rem_6rem] sm:items-center sm:gap-3 sm:px-5 text-sm">
                    <span class="text-ink break-all">{{ $entry->email }}</span>
                    <span><x-ui.badge :variant="$entry->reason === 'complaint' ? 'danger' : ($entry->reason === 'manual' ? 'neutral' : 'warn')">{{ $entry->reason }}</x-ui.badge></span>
                    <time class="text-ink-subtle sm:text-right" datetime="{{ optional($entry->created_at)->toIso8601String() }}">
                        {{ optional($entry->created_at)->diffForHumans() }}
                    </time>
                    <span class="sm:text-right">
                        <x-ui.button type="button" variant="ghost" size="sm"
                                     wire:click="remove('{{ addslashes($entry->email) }}')"
                                     wire:confirm="Allow mail to {{ $entry->email }} again?">Remove</x-ui.button>
                    </span>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-ink-subtle sm:px-5">No suppressed addresses.</p>
            @endforelse
        </div>
    </x-ui.card>

    <div>{{ $entries->links() }}</div>
</div>
