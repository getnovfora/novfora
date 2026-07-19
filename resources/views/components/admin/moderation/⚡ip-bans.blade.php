<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Post;
use App\Models\User;
use App\Moderation\IpBanGuard;
use App\Moderation\IpBanService;
use App\Permissions\Scope;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Admin → Moderation → IP & range bans (U13 / NOV-111, ADR-0121). Manage the ban types that had no UI —
 * ip / range (CIDR) / email — and an IP investigation lookup (registration attempts, posts, sessions, and
 * whether the address is currently banned). All ban writes route through IpBanService (which validates CIDR
 * via CidrMatcher and invalidates the enforcement cache). IP data is PII → the whole page is gated at the
 * users.manage ceiling in addition to bans.manage + staff-2FA, re-asserted in mount() AND every action.
 */
new class extends Component
{
    public string $type = 'ip';

    public string $value = '';

    public string $reason = '';

    public string $expires = '';

    public string $lookup = '';

    public ?int $liftId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function save(IpBanService $service): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'type' => ['required', 'in:ip,range,email'],
            'value' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expires' => ['nullable', 'date', 'after:now'],
        ]);
        try {
            $service->create($data['type'], $data['value'], $data['reason'] ?: null, $data['expires'] ? \Illuminate\Support\Carbon::parse($data['expires']) : null);
            $this->reset(['value', 'reason', 'expires']);
            $this->flash('Ban added.', 'success');
        } catch (\InvalidArgumentException $e) {
            $this->addError('value', $e->getMessage());
        }
    }

    public function lift(int $id, IpBanService $service): void
    {
        $this->ensureAdmin();
        $ban = Ban::query()->whereIn('type', ['ip', 'range', 'email'])->findOrFail($id);
        $service->lift($ban);
        $this->flash('Ban lifted.', 'success');
    }

    /** @return list<Ban> */
    public function bans(): array
    {
        $this->ensureAdmin();

        return app(IpBanService::class)->active();
    }

    /**
     * IP investigation: registration attempts, authored posts, sessions, and ban status for the looked-up IP.
     *
     * @return array{ip:string,banned:bool,registrations:int,posts:int,sessions:int,users:list<string>}|null
     */
    public function investigation(): ?array
    {
        $ip = trim($this->lookup);
        if ($ip === '' || @inet_pton($ip) === false) {
            return null;
        }
        $this->ensureAdmin();

        // Users who authored a post OR registered from this IP.
        $userIds = Post::query()->where('ip_address', $ip)->distinct()->pluck('user_id')
            ->merge(DB::table('registration_checks')->where('ip_address', $ip)->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->unique()->filter()->values();
        $usernames = User::query()->whereIn('id', $userIds)->limit(20)->pluck('username')->all();

        return [
            'ip' => $ip,
            'banned' => app(IpBanGuard::class)->isBanned($ip),
            'registrations' => (int) DB::table('registration_checks')->where('ip_address', $ip)->count(),
            'posts' => (int) Post::query()->where('ip_address', $ip)->count(),
            'sessions' => (int) DB::table('sessions')->where('ip_address', $ip)->count(),
            'users' => $usernames,
        ];
    }

    private function flash(string $message, string $variant = 'info'): void
    {
        $this->message = $message;
        $this->messageVariant = $variant;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_unless($u->canDo('bans.manage', Scope::global()), 403);
        // IP investigation exposes PII (addresses tied to accounts) → gate at the users.manage ceiling.
        abort_unless($u->canDo('users.manage', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6" dusk="acp-ip-bans">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    {{-- Add a ban. --}}
    <x-ui.card>
        <form wire:submit="save" class="space-y-4">
            <h2 class="text-sm font-semibold text-ink">Add a ban</h2>
            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <label for="ban-type" class="block text-xs font-medium text-ink-muted">Type</label>
                    <select id="ban-type" wire:model="type" class="mt-1 w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                        <option value="ip">IP address</option>
                        <option value="range">IP range (CIDR)</option>
                        <option value="email">Email</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <x-ui.input label="Value" name="value" wire:model="value" placeholder="203.0.113.5 · 203.0.113.0/24 · 2001:db8::/32 · spammer@x.test" dusk="acp-ban-value" />
                </div>
                <div>
                    <x-ui.input label="Expires (optional)" name="expires" wire:model="expires" type="datetime-local" />
                </div>
            </div>
            <x-ui.input label="Reason (optional)" name="reason" wire:model="reason" maxlength="255" />
            <x-ui.button type="submit" dusk="acp-ban-save">Add ban</x-ui.button>
        </form>
    </x-ui.card>

    {{-- IP investigation lookup. --}}
    <x-ui.card>
        <div class="space-y-3">
            <h2 class="text-sm font-semibold text-ink">IP investigation</h2>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-64 flex-1">
                    <x-ui.input label="Look up an IP address" name="lookup" wire:model.live.debounce.500ms="lookup" placeholder="203.0.113.5" dusk="acp-ip-lookup" />
                </div>
            </div>
            @php($inv = $this->investigation())
            @if ($inv)
                <div class="rounded-md border border-line bg-surface-sunken p-4 text-sm" dusk="acp-ip-investigation">
                    <p class="font-mono text-ink">{{ $inv['ip'] }}
                        @if ($inv['banned']) <x-ui.badge variant="danger">Banned</x-ui.badge> @else <x-ui.badge variant="neutral">Not banned</x-ui.badge> @endif
                    </p>
                    <p class="mt-1 text-ink-muted">{{ $inv['registrations'] }} registration attempts · {{ $inv['posts'] }} posts · {{ $inv['sessions'] }} sessions</p>
                    @if ($inv['users'] !== [])
                        <p class="mt-1 text-ink-muted">Accounts: {{ implode(', ', $inv['users']) }}</p>
                    @endif
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Active value bans. --}}
    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->bans() as $ban)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <x-ui.badge variant="neutral">{{ $ban->type }}</x-ui.badge>
                            <span class="font-mono text-ink">{{ $ban->value }}</span>
                        </div>
                        <p class="truncate text-xs text-ink-subtle">
                            {{ $ban->reason ?: 'No reason recorded' }}
                            @if ($ban->expires_at) · expires {{ $ban->expires_at->diffForHumans() }} @else · permanent @endif
                        </p>
                    </div>
                    <x-ui.button type="button" variant="danger-ghost" size="sm" wire:click="lift({{ $ban->id }})" dusk="acp-ban-lift-{{ $ban->id }}">Lift</x-ui.button>
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No IP, range, or email bans. Add one above.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
