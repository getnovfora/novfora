<?php
// SPDX-License-Identifier: Apache-2.0
use App\Install\DatabaseVerifier;
use App\Install\InstallInput;
use App\Install\InstallRunner;
use App\Install\Installer;
use App\Install\RequirementChecker;
use App\Services\Tier\ServiceTier;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * The no-SSH web installer wizard (M5). Drives the whole install from the browser: system check + tier
 * detection → database → site & administrator → review → run. The actual mutation (write .env, migrate,
 * seed, create admin, lock) is delegated to App\Install\InstallRunner — the SAME path the CLI uses.
 *
 * Security: every input is validated server-side here; the DB/admin passwords are deferred wire:models
 * (not sent per keystroke) and never rendered back; runInstall() hard-refuses once installed.
 */
new class extends Component
{
    public int $step = 1;

    // Setup token (step 1, phase-1.5 F-A) — proves filesystem access before the wizard proceeds.
    public string $setupToken = '';

    // Database (step 2).
    public string $dbDriver = 'mysql';
    public string $dbHost = '127.0.0.1';
    public int $dbPort = 3306;
    public string $dbDatabase = 'novfora';
    public string $dbUsername = 'novfora';
    public string $dbPassword = '';
    public bool $dbTested = false;
    public ?string $dbMessage = null;
    public bool $dbOk = false;

    // Site & administrator (step 3).
    public string $siteName = 'My Community';
    public string $appUrl = '';
    public string $adminUsername = '';
    public string $adminEmail = '';
    public string $adminPassword = '';
    public string $passwordConfirmation = '';
    public bool $seedDemo = true;

    // Outcome (step 5).
    public ?string $installError = null;
    public bool $storageLinked = false;
    public bool $demoSeeded = false;
    public array $resultNotes = [];

    public function mount(): void
    {
        $this->appUrl = rtrim((string) request()->getSchemeAndHttpHost(), '/');
    }

    public function requirements(): array
    {
        return app(RequirementChecker::class)->run();
    }

    public function tier(): \App\Services\Tier\TierSnapshot
    {
        return app(ServiceTier::class)->snapshot(fresh: true);
    }

    /** @return array<string, array<int, string>> */
    private function dbRules(): array
    {
        return [
            'dbDriver' => ['required', Rule::in(['mysql', 'mariadb', 'pgsql', 'sqlite'])],
            'dbHost' => ['required_unless:dbDriver,sqlite', 'string', 'max:255'],
            'dbPort' => ['required_unless:dbDriver,sqlite', 'integer', 'between:1,65535'],
            'dbDatabase' => ['required', 'string', 'max:255'],
            'dbUsername' => ['required_unless:dbDriver,sqlite', 'string', 'max:255'],
            'dbPassword' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    private function siteRules(): array
    {
        return [
            'siteName' => ['required', 'string', 'max:60'],
            'appUrl' => ['required', 'url', 'max:255'],
            'adminUsername' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30'],
            'adminEmail' => ['required', 'email', 'max:255'],
            'adminPassword' => ['required', 'string', Password::min(10)->mixedCase()->numbers(), 'same:passwordConfirmation'],
            'seedDemo' => ['boolean'],
        ];
    }

    public function toStep2(): void
    {
        // Hard requirements gate the install (recommendations only warn).
        abort_unless($this->requirements()['ok'], 422);

        // Setup-token gate (phase-1.5 F-A): block the rest of the wizard — including the DB-test SSRF — until
        // the operator proves filesystem access by entering the token from storage/install-token.txt.
        if (! app(Installer::class)->verifyToken($this->setupToken)) {
            $this->addError('setupToken', 'That setup token is incorrect. Find it in storage/install-token.txt on your server (via FTP or your host file manager).');

            return;
        }

        $this->step = 2;
    }

    public function tokenRequired(): bool
    {
        return app(Installer::class)->requiresToken();
    }

    public function testDatabase(): void
    {
        $this->validate($this->dbRules());

        $result = app(DatabaseVerifier::class)->verify(
            $this->dbDriver, $this->dbHost, (int) $this->dbPort, $this->dbDatabase, $this->dbUsername, $this->dbPassword,
        );

        $this->dbTested = true;
        $this->dbOk = $result['ok'];
        $this->dbMessage = $result['message'];
    }

    public function toStep3(): void
    {
        $this->validate($this->dbRules());
        if (! $this->dbOk) {
            $this->testDatabase();
            if (! $this->dbOk) {
                return; // surface the connection problem; don't advance on an unverified DB
            }
        }
        $this->step = 3;
    }

    public function toStep4(): void
    {
        $this->validate($this->siteRules());
        $this->step = 4;
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function runInstall(): void
    {
        // The lock, enforced at the action too (defence in depth beyond the route + the runner).
        abort_if(app(Installer::class)->isInstalled(), 403, 'NovFora is already installed.');

        $this->validate($this->dbRules() + $this->siteRules());
        $this->installError = null;

        try {
            $result = app(InstallRunner::class)->run(new InstallInput(
                siteName: $this->siteName,
                appUrl: rtrim($this->appUrl, '/'),
                dbDriver: $this->dbDriver,
                dbHost: $this->dbHost,
                dbPort: (int) $this->dbPort,
                dbDatabase: $this->dbDatabase,
                dbUsername: $this->dbUsername,
                dbPassword: $this->dbPassword,
                adminUsername: $this->adminUsername,
                adminEmail: $this->adminEmail,
                adminPassword: $this->adminPassword,
                seedDemo: $this->seedDemo,
                setupToken: $this->setupToken,
            ));

            $this->storageLinked = $result->storageLinked;
            $this->demoSeeded = $result->demoSeeded;
            $this->resultNotes = $result->notes;

            // Drop the plaintext passwords from component state the moment we're done with them.
            $this->dbPassword = $this->adminPassword = $this->passwordConfirmation = '';
            $this->step = 5;
        } catch (\Throwable $e) {
            $this->installError = $e->getMessage();
        }
    }

    public function cronLine(): string
    {
        return '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
    }
};
?>

<div class="card" wire:loading.class="is-busy">
    <ol class="steps">
        <li @if($step===1) aria-current="step" @endif>1 · System</li>
        <li @if($step===2) aria-current="step" @endif>2 · Database</li>
        <li @if($step===3) aria-current="step" @endif>3 · Site &amp; admin</li>
        <li @if($step===4) aria-current="step" @endif>4 · Install</li>
        <li @if($step===5) aria-current="step" @endif>5 · Done</li>
    </ol>

    {{-- ── Step 1 — system check + tier detection ─────────────────────────────────────────── --}}
    @if ($step === 1)
        @php($req = $this->requirements())
        @php($snap = $this->tier())
        <h2>System check</h2>
        <p class="muted">NovFora runs on a baseline shared host (PHP 8.3+, MySQL, cron). Red items must be fixed before continuing; amber items are recommendations.</p>

        <div role="list">
            @foreach ($req['checks'] as $c)
                <div class="check" role="listitem">
                    <span class="badge {{ $c['status'] }}">{{ $c['status'] === 'pass' ? '✓' : ($c['status'] === 'warn' ? '!' : '✕') }}</span>
                    <span><strong>{{ $c['name'] }}</strong> — <span class="muted">{{ $c['detail'] }}</span></span>
                </div>
            @endforeach
        </div>

        <h3 style="margin-top:1.25rem">Detected deployment tier: {{ $snap->overall->label() }}</h3>
        <p class="muted">The same code runs on both tiers. Optional services (Redis, Meilisearch, Reverb, S3) light up automatically when you configure them later — no reinstall.</p>

        @unless ($req['ok'])
            <p class="err">Some required checks failed. Fix them on your host, then re-run the check.</p>
        @endunless

        @if ($this->tokenRequired())
            <div class="note" style="margin-top:1.25rem">
                <strong>Setup token.</strong> For security, the installer is locked to whoever can read a file
                on your server. Open <code>storage/install-token.txt</code> (via FTP or your host's file
                manager) and paste its contents below.
            </div>
            <label for="setupToken">Setup token</label>
            <input id="setupToken" type="text" wire:model="setupToken" autocomplete="off"
                   style="width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #cfcfd6;border-radius:6px">
            @error('setupToken')<div class="err">{{ $message }}</div>@enderror
        @endif

        <div class="actions">
            <span></span>
            <span>
                <button type="button" class="btn-ghost btn" wire:click="$refresh">Re-check</button>
                <button type="button" class="btn" wire:click="toStep2" @disabled(! $req['ok'])>Continue</button>
            </span>
        </div>
    @endif

    {{-- ── Step 2 — database ──────────────────────────────────────────────────────────────── --}}
    @if ($step === 2)
        <h2>Database connection</h2>
        <p class="muted">Create an empty database in your host's control panel, then enter its details. Nothing is written until you finish.</p>

        <label for="dbDriver">Engine</label>
        <select id="dbDriver" wire:model="dbDriver">
            <option value="mysql">MySQL / MariaDB (recommended)</option>
            <option value="pgsql">PostgreSQL</option>
            <option value="sqlite">SQLite (single-file)</option>
        </select>

        @if ($dbDriver !== 'sqlite')
            <div class="row">
                <div><label for="dbHost">Host</label><input id="dbHost" type="text" wire:model="dbHost">@error('dbHost')<div class="err">{{ $message }}</div>@enderror</div>
                <div style="max-width:8rem"><label for="dbPort">Port</label><input id="dbPort" type="number" wire:model="dbPort">@error('dbPort')<div class="err">{{ $message }}</div>@enderror</div>
            </div>
            <label for="dbDatabase">Database name</label><input id="dbDatabase" type="text" wire:model="dbDatabase">@error('dbDatabase')<div class="err">{{ $message }}</div>@enderror
            <div class="row">
                <div><label for="dbUsername">Username</label><input id="dbUsername" type="text" wire:model="dbUsername">@error('dbUsername')<div class="err">{{ $message }}</div>@enderror</div>
                <div><label for="dbPassword">Password</label><input id="dbPassword" type="password" wire:model="dbPassword" autocomplete="off">@error('dbPassword')<div class="err">{{ $message }}</div>@enderror</div>
            </div>
        @else
            <label for="dbDatabase">SQLite file path</label>
            <input id="dbDatabase" type="text" wire:model="dbDatabase" placeholder="{{ database_path('database.sqlite') }}">
            @error('dbDatabase')<div class="err">{{ $message }}</div>@enderror
        @endif

        <div style="margin-top:1rem">
            <button type="button" class="btn-ghost btn" wire:click="testDatabase">Test connection</button>
            @if ($dbTested)
                <span class="{{ $dbOk ? 'pass' : 'fail' }}" style="margin-left:.5rem">{{ $dbOk ? '✓' : '✕' }} {{ $dbMessage }}</span>
            @endif
        </div>

        <div class="actions">
            <button type="button" class="btn-ghost btn" wire:click="back">Back</button>
            <button type="button" class="btn" wire:click="toStep3">Continue</button>
        </div>
    @endif

    {{-- ── Step 3 — site & administrator ──────────────────────────────────────────────────── --}}
    @if ($step === 3)
        <h2>Site &amp; administrator</h2>

        <label for="siteName">Community name</label>
        <input id="siteName" type="text" wire:model="siteName">@error('siteName')<div class="err">{{ $message }}</div>@enderror

        <label for="appUrl">Site URL</label>
        <input id="appUrl" type="url" wire:model="appUrl">@error('appUrl')<div class="err">{{ $message }}</div>@enderror

        <h3 style="margin-top:1.25rem">Administrator account</h3>
        <p class="muted">This is the first staff account. You'll be asked to set up two-factor authentication on your first sign-in (required for staff).</p>
        <div class="row">
            <div><label for="adminUsername">Username</label><input id="adminUsername" type="text" wire:model="adminUsername" autocomplete="off">@error('adminUsername')<div class="err">{{ $message }}</div>@enderror</div>
            <div><label for="adminEmail">Email</label><input id="adminEmail" type="email" wire:model="adminEmail" autocomplete="off">@error('adminEmail')<div class="err">{{ $message }}</div>@enderror</div>
        </div>
        <div class="row">
            <div><label for="adminPassword">Password</label><input id="adminPassword" type="password" wire:model="adminPassword" autocomplete="new-password">@error('adminPassword')<div class="err">{{ $message }}</div>@enderror</div>
            <div><label for="passwordConfirmation">Confirm password</label><input id="passwordConfirmation" type="password" wire:model="passwordConfirmation" autocomplete="new-password"></div>
        </div>

        <label style="font-weight:400;margin-top:1rem">
            <input type="checkbox" wire:model="seedDemo"> Start with a demo community (example categories, forums, and posts you can delete later)
        </label>

        <div class="actions">
            <button type="button" class="btn-ghost btn" wire:click="back">Back</button>
            <button type="button" class="btn" wire:click="toStep4">Continue</button>
        </div>
    @endif

    {{-- ── Step 4 — review & install ──────────────────────────────────────────────────────── --}}
    @if ($step === 4)
        <h2>Review &amp; install</h2>
        <p class="muted">No secrets are shown below. Installing writes your settings, sets up the database, and creates your admin account.</p>
        <table class="kv">
            <tr><td>Community name</td><td><strong>{{ $siteName }}</strong></td></tr>
            <tr><td>Site URL</td><td>{{ $appUrl }}</td></tr>
            <tr><td>Database</td><td>{{ $dbDriver }} · {{ $dbDriver === 'sqlite' ? $dbDatabase : $dbDatabase.' @ '.$dbHost.':'.$dbPort }}</td></tr>
            <tr><td>Administrator</td><td>{{ $adminUsername }} &lt;{{ $adminEmail }}&gt;</td></tr>
            <tr><td>Demo content</td><td>{{ $seedDemo ? 'Yes' : 'No (empty start)' }}</td></tr>
        </table>

        @if ($installError)
            <p class="err" style="margin-top:1rem">Install failed: {{ $installError }}</p>
            <p class="muted">Nothing was locked — fix the issue and try again.</p>
        @endif

        <div class="actions">
            <button type="button" class="btn-ghost btn" wire:click="back" wire:loading.attr="disabled">Back</button>
            <button type="button" class="btn" wire:click="runInstall" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="runInstall">Install NovFora</span>
                <span wire:loading wire:target="runInstall">Installing…</span>
            </button>
        </div>
    @endif

    {{-- ── Step 5 — done ──────────────────────────────────────────────────────────────────── --}}
    @if ($step === 5)
        <h2 class="pass">✓ {{ $siteName }} is installed</h2>
        <p>Your community is ready. The installer is now locked and will not run again.</p>

        <div class="note">
            <strong>One last step — the cron line.</strong> Add this single line to your host's cron panel so
            email, search indexing, trust levels, and backups all run automatically:
            <pre style="white-space:pre-wrap;margin:.5rem 0 0"><code>{{ $this->cronLine() }}</code></pre>
        </div>

        <div class="note">
            <strong>Sign in &amp; secure your account.</strong> Your admin account requires two-factor
            authentication — you'll be guided to set it up on first sign-in.
        </div>

        @unless ($storageLinked)
            <div class="note"><strong>Heads up:</strong> the <code>public/storage</code> symlink couldn't be created
            automatically. Run <code>php artisan storage:link</code> (or copy <code>storage/app/public</code> to
            <code>public/storage</code>) so uploaded avatars and images display.</div>
        @endunless

        @unless (extension_loaded('gd') || extension_loaded('imagick'))
            <div class="note"><strong>Image thumbnails:</strong> neither GD nor Imagick is enabled, so uploaded
            images won't be resized into thumbnails. Ask your host to enable one of them.</div>
        @endunless

        @foreach ($resultNotes as $note)
            <div class="note">{{ $note }}</div>
        @endforeach

        <div class="actions">
            <span></span>
            <a class="btn" href="{{ route('login') }}">Sign in to your community →</a>
        </div>
    @endif
</div>
