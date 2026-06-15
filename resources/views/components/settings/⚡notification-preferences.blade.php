<?php
// SPDX-License-Identifier: Apache-2.0
use App\Deliverability\SuppressionGate;
use App\Http\Controllers\NotificationController;
use App\Models\DigestPreference;
use App\Models\NotificationPreference;
use App\Models\User;
use Livewire\Component;

/**
 * Settings → Notifications (P2-M2). Per-event × per-channel toggles PLUS the digest cadence picker over
 * DigestPreference (off / immediate / daily / weekly). OWN-PREFS-ONLY: it never accepts a user id — it always
 * reads and writes auth()->user(), with auth re-asserted in mount() AND in save(). Absent preference row = the
 * default (on); absent cadence row = immediate (the live default).
 *
 * The $prefs array is keyed by a DOTLESS composite ("{event}_{channel}", dots in event names replaced) because
 * Livewire treats a dot in a wire:model path as array nesting — and `pm.received` carries a dot.
 */
new class extends Component
{
    /** @var array<string,bool> keyed by prefKey() */
    public array $prefs = [];

    public string $cadence = DigestPreference::IMMEDIATE;

    public ?string $flash = null;

    /** Friendly labels for the event vocabulary (NotificationController::EVENTS). */
    public const EVENT_LABELS = [
        'reply' => 'Replies to your topics',
        'mention' => 'Mentions of you',
        'reaction' => 'Reactions to your posts',
        'pm.received' => 'Private messages',
        'follow' => 'New followers',
        'moderation' => 'Moderation notices',
    ];

    public const CADENCE_LABELS = [
        DigestPreference::IMMEDIATE => 'Immediately — one email per notification (default)',
        DigestPreference::DAILY => 'Daily digest — one email a day',
        DigestPreference::WEEKLY => 'Weekly digest — one email a week',
        DigestPreference::OFF => 'Off — no notification emails',
    ];

    /** Column labels for the channel matrix (M3.3 adds Push). */
    public const CHANNEL_LABELS = ['database' => 'In-app', 'mail' => 'Email', 'push' => 'Push'];

    public function mount(): void
    {
        $user = $this->user();

        $stored = NotificationPreference::where('user_id', $user->getKey())->get()
            ->mapWithKeys(fn (NotificationPreference $p) => ["{$p->event_type}.{$p->channel}" => (bool) $p->enabled]);

        foreach (NotificationController::EVENTS as $event) {
            foreach (NotificationController::CHANNELS as $channel) {
                $this->prefs[$this->prefKey($event, $channel)] = $stored["{$event}.{$channel}"] ?? true; // absent = on
            }
        }

        $this->cadence = app(SuppressionGate::class)->cadence($user);
    }

    public function save(): void
    {
        $user = $this->user();

        $cadence = in_array($this->cadence, DigestPreference::CADENCES, true) ? $this->cadence : DigestPreference::IMMEDIATE;

        foreach (NotificationController::EVENTS as $event) {
            foreach (NotificationController::CHANNELS as $channel) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $user->getKey(), 'event_type' => $event, 'channel' => $channel],
                    ['enabled' => (bool) ($this->prefs[$this->prefKey($event, $channel)] ?? true)],
                );
            }
        }

        DigestPreference::updateOrCreate(['user_id' => $user->getKey()], ['cadence' => $cadence]);

        $this->cadence = $cadence;
        $this->flash = 'Notification preferences saved.';
    }

    /** Dotless, collision-free composite key for an (event, channel) pair. */
    public function prefKey(string $event, string $channel): string
    {
        return str_replace('.', '_', $event).'_'.$channel;
    }

    public function eventLabel(string $event): string
    {
        return self::EVENT_LABELS[$event] ?? ucfirst(str_replace('.', ' ', $event));
    }

    public function channelLabel(string $channel): string
    {
        return self::CHANNEL_LABELS[$channel] ?? ucfirst($channel);
    }

    /** @return list<string> */
    public function events(): array
    {
        return NotificationController::EVENTS;
    }

    /** @return list<string> */
    public function channels(): array
    {
        return NotificationController::CHANNELS;
    }

    /** @return array<string,string> */
    public function cadences(): array
    {
        return self::CADENCE_LABELS;
    }

    private function user(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }
};
?>

<div class="space-y-5">
    @if ($flash)
        <x-ui.alert variant="success">{{ $flash }}</x-ui.alert>
    @endif

    {{-- Web Push device enablement (M3.3). Push notifications also require turning them on FOR THIS DEVICE.
         Browser-only (inline Alpine; no bundle rebuild) — it reads the VAPID public key from /push/public-key,
         subscribes via the service worker, and POSTs the subscription. Degrades silently where unsupported. --}}
    <x-ui.card>
        <div x-data="{
                supported: ('serviceWorker' in navigator) && ('PushManager' in window),
                available: false, subscribed: false, busy: false, error: '',
                async init() {
                    if (!this.supported) return;
                    try {
                        const r = await fetch('{{ route('push.public-key') }}', { headers: { 'Accept': 'application/json' } });
                        const d = await r.json();
                        this.available = !!d.enabled; this.publicKey = d.publicKey || '';
                        if (this.available) {
                            const reg = await navigator.serviceWorker.ready;
                            this.subscribed = !!(await reg.pushManager.getSubscription());
                        }
                    } catch (e) { this.error = ''; }
                },
                _key(b) { const p='='.repeat((4-b.length%4)%4); const s=(b+p).replace(/-/g,'+').replace(/_/g,'/'); const raw=atob(s); const a=new Uint8Array(raw.length); for(let i=0;i<raw.length;i++)a[i]=raw.charCodeAt(i); return a; },
                _csrf() { return document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || ''; },
                async enable() {
                    this.busy = true; this.error = '';
                    try {
                        if ((await Notification.requestPermission()) !== 'granted') { this.error = 'Permission was not granted.'; this.busy=false; return; }
                        const reg = await navigator.serviceWorker.ready;
                        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this._key(this.publicKey) });
                        await fetch('{{ route('push.subscribe') }}', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this._csrf()}, body: JSON.stringify(sub.toJSON()) });
                        this.subscribed = true;
                    } catch (e) { this.error = 'Could not enable push on this device.'; }
                    this.busy = false;
                },
                async disable() {
                    this.busy = true; this.error = '';
                    try {
                        const reg = await navigator.serviceWorker.ready;
                        const sub = await reg.pushManager.getSubscription();
                        if (sub) { await fetch('{{ route('push.unsubscribe') }}', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this._csrf()}, body: JSON.stringify({ endpoint: sub.endpoint }) }); await sub.unsubscribe(); }
                        this.subscribed = false;
                    } catch (e) { this.error = 'Could not disable push.'; }
                    this.busy = false;
                }
             }" x-init="init()" class="space-y-2">
            <h2 class="text-sm font-semibold text-ink">{{ __('Push notifications on this device') }}</h2>
            <p class="text-sm text-ink-muted" x-show="!supported">{{ __('Your browser does not support push notifications.') }}</p>
            <p class="text-sm text-ink-muted" x-show="supported && !available">{{ __('Push notifications are not enabled on this site yet.') }}</p>
            <template x-if="supported && available">
                <div class="space-y-2">
                    <p class="text-sm text-ink-muted" x-show="subscribed">{{ __('Push is on for this device. You can turn it off below.') }}</p>
                    <p class="text-sm text-ink-muted" x-show="!subscribed">{{ __('Get notified on this device even when NovFora isn’t open. Per-event push delivery is controlled in the table below.') }}</p>
                    <x-ui.button type="button" x-show="!subscribed" @click="enable()" x-bind:disabled="busy" dusk="push-enable"
                                 x-text="busy ? '{{ __('Enabling…') }}' : '{{ __('Enable on this device') }}'"></x-ui.button>
                    <x-ui.button type="button" variant="ghost" x-show="subscribed" @click="disable()" x-bind:disabled="busy" dusk="push-disable"
                                 x-text="busy ? '{{ __('Disabling…') }}' : '{{ __('Disable on this device') }}'"></x-ui.button>
                    <p class="text-sm text-danger" x-show="error" x-text="error"></p>
                </div>
            </template>
        </div>
    </x-ui.card>

    <form wire:submit="save" class="space-y-5">
        {{-- Per-event × per-channel toggles. Push delivers only to devices enabled above. --}}
        <x-ui.card flush>
            <div class="hidden sm:grid grid-cols-[1fr_5rem_5rem_5rem] gap-3 px-4 py-2.5 sm:px-5 border-b border-line bg-surface-sunken text-xs font-semibold uppercase tracking-wide text-ink-subtle">
                <span>Event</span>
                @foreach ($this->channels() as $channel)
                    <span class="text-center">{{ $this->channelLabel($channel) }}</span>
                @endforeach
            </div>
            <ul class="divide-y divide-line">
                @foreach ($this->events() as $event)
                    <li class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-[1fr_5rem_5rem_5rem] sm:items-center sm:gap-3 sm:px-5">
                        <p class="text-sm font-medium text-ink">{{ $this->eventLabel($event) }}</p>
                        @foreach ($this->channels() as $channel)
                            @php($k = $this->prefKey($event, $channel))
                            <label class="inline-flex min-h-11 items-center gap-2 sm:justify-center cursor-pointer select-none">
                                <input type="checkbox" wire:model="prefs.{{ $k }}" value="1"
                                       aria-label="{{ $this->eventLabel($event) }} — {{ $this->channelLabel($channel) }}"
                                       class="h-4 w-4 rounded-sm border-line-strong text-accent focus-visible:ring-accent">
                                <span class="text-sm text-ink-muted sm:hidden">{{ $this->channelLabel($channel) }}</span>
                            </label>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        {{-- Digest cadence: how the EMAIL channel is delivered. Daily/weekly batch into one digest; off stops
             all notification email; immediate is the live default. (In-app notifications are unaffected.) --}}
        <x-ui.card>
            <h2 class="text-sm font-semibold text-ink">Email digest</h2>
            <p class="mt-1 text-sm text-ink-muted">
                Choose how notification <strong>emails</strong> are delivered. Daily and weekly batch your emails
                into a single digest; “Off” stops all notification email. In-app notifications are unaffected.
            </p>
            <fieldset class="mt-3 space-y-2">
                @foreach ($this->cadences() as $value => $label)
                    <label class="flex min-h-11 items-center gap-2 cursor-pointer select-none">
                        <input type="radio" wire:model="cadence" value="{{ $value }}"
                               class="h-4 w-4 border-line-strong text-accent focus-visible:ring-accent">
                        <span class="text-sm text-ink-muted">{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
        </x-ui.card>

        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save preferences</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </form>
</div>
