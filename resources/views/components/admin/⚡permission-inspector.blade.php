<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\PermissionInspector;
use App\Permissions\Scope;
use Livewire\Component;

new class extends Component
{
    public string $userRef = '';

    public string $permission = '';

    public string $scopeRef = 'global';

    /** @var array<string,mixed>|null */
    public ?array $report = null;

    public ?string $error = null;

    public function inspect(): void
    {
        $this->report = null;
        $this->error = null;

        $user = is_numeric($this->userRef)
            ? User::find((int) $this->userRef)
            : User::where('email', $this->userRef)->orWhere('username', $this->userRef)->first();

        if (! $user) {
            $this->error = "No user matched [{$this->userRef}].";

            return;
        }

        try {
            $scope = Scope::parse($this->scopeRef !== '' ? $this->scopeRef : 'global');
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->report = app(PermissionInspector::class)->inspect($user, trim($this->permission), $scope);
    }
};
?>

<div style="font-family:system-ui,sans-serif">
    <form wire:submit="inspect" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
        <label style="display:flex;flex-direction:column;font-size:.85rem;color:#444">User (id or email)
            <input wire:model="userRef" required style="padding:.4rem;border:1px solid #bbb;border-radius:4px;min-width:14rem">
        </label>
        <label style="display:flex;flex-direction:column;font-size:.85rem;color:#444">Permission key
            <input wire:model="permission" required placeholder="forum.post.create" style="padding:.4rem;border:1px solid #bbb;border-radius:4px;min-width:14rem">
        </label>
        <label style="display:flex;flex-direction:column;font-size:.85rem;color:#444">Scope
            <input wire:model="scopeRef" placeholder="global | forum:2 | thread:1" style="padding:.4rem;border:1px solid #bbb;border-radius:4px;min-width:12rem">
        </label>
        <button type="submit" wire:loading.attr="disabled" style="padding:.5rem 1rem;cursor:pointer">Explain</button>
        <span wire:loading style="color:#777">resolving…</span>
    </form>

    @if ($error)
        <p style="color:#b00020;background:#fde;border:1px solid #f9c;padding:.6rem .8rem;border-radius:4px">{{ $error }}</p>
    @endif

    @if ($report)
        @php($granted = $report['granted'])
        <div style="padding:.8rem 1rem;border-radius:6px;margin-bottom:1rem;border:1px solid {{ $granted ? '#7cc47c' : '#e08a8a' }};background:{{ $granted ? '#eefaee' : '#fdeeee' }}">
            <strong style="font-size:1.1rem;color:{{ $granted ? '#1a7a1a' : '#a11' }}">{{ $granted ? 'ALLOWED' : 'DENIED' }}</strong>
            <span style="color:#555">— {{ $report['summary'] }}</span>
        </div>

        <table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-bottom:1.25rem">
            <tbody>
                <tr><td style="color:#777">User</td><td><strong>{{ $report['user']['label'] }}</strong> (#{{ $report['user']['id'] }}, {{ $report['user']['status'] }})</td></tr>
                <tr><td style="color:#777">Permission</td><td><code>{{ $report['permission'] }}</code></td></tr>
                <tr><td style="color:#777">Scope</td><td><code>{{ $report['scope'] }}</code></td></tr>
                <tr><td style="color:#777">Decisive rule</td><td><code>{{ $report['reason'] }}</code>@if ($report['decided_by']) <span style="color:#777">by {{ $report['decided_by'] }} @ {{ $report['decided_at_scope'] ?? '—' }}</span>@endif</td></tr>
                <tr><td style="color:#777">Scope chain</td><td><code>{{ implode('  →  ', $report['scope_chain']) }}</code></td></tr>
                <tr><td style="color:#777">Holders</td><td>{{ implode(', ', $report['holders']) }}</td></tr>
            </tbody>
        </table>

        <h3 style="margin-bottom:.4rem">Candidate ACL entries</h3>
        @if ($report['entries'] === [])
            <p style="color:#777">No entries matched these holders for this permission in this chain — deny-by-default.</p>
        @else
            <table cellpadding="7" cellspacing="0" style="border-collapse:collapse;width:100%">
                <thead>
                    <tr style="background:#f4f4f5;text-align:left">
                        <th style="border:1px solid #ddd">Holder</th>
                        <th style="border:1px solid #ddd">Scope</th>
                        <th style="border:1px solid #ddd">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['entries'] as $entry)
                        <tr>
                            <td style="border:1px solid #ddd"><code>{{ $entry['holder'] }}</code></td>
                            <td style="border:1px solid #ddd"><code>{{ $entry['scope'] }}</code></td>
                            <td style="border:1px solid #ddd">{{ $entry['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>
