<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

/**
 * Admin → Settings → Email (ACP v1, PART 3.3). Mailer + From identity + SMTP fields, with the password
 * stored ENCRYPTED and never echoed back (the field is always blank; leaving it blank keeps the current
 * value). A "send test email" action delivers one message through the configured transport and surfaces
 * the real result. Reads current env/config as the initial values; DB overrides take precedence (PART 0).
 */
new class extends Component
{
    public string $mailer = 'log';

    public string $fromName = '';

    public string $fromAddress = '';

    public string $host = '';

    public int $port = 587;

    public string $username = '';

    public string $password = ''; // never pre-filled — it is a secret (placeholder semantics)

    public string $scheme = '';

    public bool $passwordSet = false;

    public string $testTo = '';

    public ?string $saved = null;

    public ?string $testResult = null;

    public string $testVariant = 'info';

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->mailer = $settings->string('mail.mailer');
        $this->fromName = $settings->string('mail.from_name');
        $this->fromAddress = $settings->string('mail.from_address');
        $this->host = $settings->string('mail.host');
        $this->port = $settings->int('mail.port');
        $this->username = $settings->string('mail.username');
        $this->scheme = $settings->string('mail.scheme');
        $this->passwordSet = $settings->secretIsSet('mail.password');
        $this->testTo = (string) (auth()->user()?->email ?? '');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'mailer' => ['required', 'in:log,smtp,sendmail,array'],
            'fromName' => ['required', 'string', 'max:80'],
            'fromAddress' => ['required', 'email', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'scheme' => ['nullable', 'in:,smtps'],
        ]);

        $settings->set('mail.mailer', $data['mailer']);
        $settings->set('mail.from_name', $data['fromName']);
        $settings->set('mail.from_address', $data['fromAddress']);
        $settings->set('mail.host', $data['host'] ?? '');
        $settings->set('mail.port', $data['port']);
        $settings->set('mail.username', $data['username'] ?? '');
        $settings->set('mail.password', $data['password']); // blank ⇒ keep the stored secret
        $settings->set('mail.scheme', $data['scheme'] ?? '');

        $this->password = '';
        $this->passwordSet = $settings->secretIsSet('mail.password');
        $this->saved = 'Saved.';
    }

    public function sendTest(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->validate(['testTo' => ['required', 'email']]);
        $settings->applyToConfig(); // make the saved SMTP overrides live for this send

        try {
            Mail::raw(
                ' — if you received this, outbound email is working. For reliable '
                .'delivery, verify SPF, DKIM and DMARC DNS records for your sending domain.',
                fn ($message) => $message->to($this->testTo)->subject(''),
            );
            $this->testResult = 'Test email sent to '.$this->testTo.'. Check that inbox (and the spam folder).';
            $this->testVariant = 'success';
        } catch (\Throwable $e) {
            $this->testResult = 'Send failed: '.class_basename($e).'. Re-check the SMTP settings above.';
            $this->testVariant = 'danger';
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

<div class="space-y-6">
    <form wire:submit="save" class="space-y-5">
        @if ($saved)
            <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
        @endif

        <div id="setting-mail-mailer">
            <x-ui.select label="Mailer" name="mailer" wire:model="mailer"
                         hint="“log” writes mail to the log (safe default); “smtp” sends via the server below.">
                <option value="log">Log (no real delivery)</option>
                <option value="smtp">SMTP</option>
                <option value="sendmail">Sendmail</option>
                <option value="array">Array (testing)</option>
            </x-ui.select>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div id="setting-mail-from-name"><x-ui.input label="From name" name="fromName" wire:model="fromName" required /></div>
            <div id="setting-mail-from-address"><x-ui.input label="From address" name="fromAddress" type="email" wire:model="fromAddress" required /></div>
        </div>

        <fieldset class="space-y-5 border-t border-line pt-5">
            <legend class="text-sm font-semibold text-ink">SMTP server</legend>
            <div class="grid gap-5 sm:grid-cols-2">
                <div id="setting-mail-host"><x-ui.input label="Host" name="host" wire:model="host" placeholder="smtp.example.com" /></div>
                <div id="setting-mail-port"><x-ui.input label="Port" name="port" type="number" wire:model="port" /></div>
                <div id="setting-mail-username"><x-ui.input label="Username" name="username" wire:model="username" autocomplete="off" /></div>
                <div id="setting-mail-password">
                    <x-ui.input label="Password" name="password" type="password" wire:model="password" autocomplete="new-password"
                                :placeholder="$passwordSet ? '•••••• (leave blank to keep)' : ''"
                                hint="Stored encrypted; never shown again." />
                </div>
            </div>
            <div id="setting-mail-scheme">
                <x-ui.select label="Encryption" name="scheme" wire:model="scheme">
                    <option value="">Automatic (STARTTLS)</option>
                    <option value="smtps">SSL/TLS (implicit, port 465)</option>
                </x-ui.select>
            </div>
        </fieldset>

        <div>
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Save changes</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>

    <x-ui.card>
        <h2 class="text-sm font-semibold text-ink">Send a test email</h2>
        <p class="mt-1 text-sm text-ink-muted">Delivers one message through the configured transport so you can confirm it works.</p>
        @if ($testResult)
            <x-ui.alert :variant="$testVariant" class="mt-3">{{ $testResult }}</x-ui.alert>
        @endif
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <x-ui.input label="Send to" name="testTo" type="email" wire:model="testTo" />
            </div>
            <x-ui.button type="button" variant="subtle" wire:click="sendTest"
                         wire:loading.attr="disabled" wire:target="sendTest">
                <span wire:loading.remove wire:target="sendTest">Send test</span>
                <span wire:loading wire:target="sendTest">Sending…</span>
            </x-ui.button>
        </div>
    </x-ui.card>
</div>
