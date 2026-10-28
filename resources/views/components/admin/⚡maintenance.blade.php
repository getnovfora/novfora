<?php
// SPDX-License-Identifier: Apache-2.0
use App\Jobs\RebuildCountersJob;
use App\Models\User;
use App\Permissions\Scope;
use App\Support\Mail\TestMailer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Admin → System → Maintenance (U16 / NOV-114). Operator housekeeping with no schema: clear the compiled
 * caches, queue a counter self-heal, tail the application log (read-only + secret-redacted), and send a mail
 * self-test. Authorization is enforced IN the component (mount + every action), not only on the route —
 * Livewire actions reach the component via livewire/update, which carries no admin/system route middleware.
 * Gated at admin.access + admin.system.access + staff-2FA. All actions are safe/reversible: the cache clear
 * touches only pure-rebuild framework caches (never Cache::flush(), which would drop the ban list /
 * rate-limit / queue-heartbeat state), and the recompute SETs authoritative counts (idempotent).
 */
new class extends Component
{
    public string $testTo = '';

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    /** Clear the always-safe framework caches — pure rebuilds, no durable state lost. */
    public function clearCaches(): void
    {
        $this->ensureAdmin();
        // The framework caches that DON'T auto-invalidate, safe to drop mid-request: they touch bootstrap/cache,
        // not the compiled Blade view this Livewire page is rendering from, and never Cache::flush() (which would
        // drop the ban list / rate-limit / queue-heartbeat state). Compiled views are omitted DELIBERATELY —
        // Blade recompiles a template the moment its source changes, so they never go stale, and clearing them
        // mid-render would delete the file this very page is rendering from. On the baseline these caches may be
        // absent (nothing cached them) → the calls no-op.
        foreach (['config:clear', 'route:clear', 'event:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable) {
                // best-effort: one unavailable cache clear must not abort the rest
            }
        }
        $this->flash('Configuration, route, and event caches cleared.', 'success');
    }

    /** Queue a full-board counter self-heal (forum/topic aggregates + users.post_count). */
    public function rebuildCounters(): void
    {
        $this->ensureAdmin();
        RebuildCountersJob::dispatch();
        $this->flash('Counter rebuild queued — it runs in the background (drained by the scheduler within ~1 minute).', 'success');
    }

    public function sendTest(TestMailer $mailer): void
    {
        $this->ensureAdmin();
        $this->validate(['testTo' => ['required', 'email']]);
        try {
            $mailer->send($this->testTo);
            $this->flash('Test email sent to '.$this->testTo.'. Check that inbox (and the spam folder).', 'success');
        } catch (\Throwable $e) {
            $this->flash('Send failed: '.class_basename($e).'. Re-check the mail settings.', 'danger');
        }
    }

    /**
     * A bounded, secret-redacted tail of the newest application log. Read-only; no user-supplied path (fixed
     * to storage/logs), so no traversal surface.
     *
     * @return list<string>
     */
    public function logTail(): array
    {
        $this->ensureAdmin();
        $file = $this->newestLogFile();
        if ($file === null) {
            return [];
        }

        // Memoise the (bounded) read+redact for a few seconds, keyed by the file's identity, so unrelated action
        // round-trips on this page (clear caches, mail test) don't re-read + re-redact the log on every render.
        // The cached value is already redacted (nothing sensitive is stored); a changed log (mtime/size) re-reads.
        $key = 'novfora:maint:log-tail:'.md5($file.'|'.(@filemtime($file) ?: 0).'|'.(@filesize($file) ?: 0));

        return Cache::remember($key, 10, fn (): array => array_map(
            fn (string $line): string => $this->redact($line),
            $this->tailLines($file, 150),
        ));
    }

    /** The most recently written laravel.log (single) or laravel-YYYY-MM-DD.log (daily) file. */
    private function newestLogFile(): ?string
    {
        $candidates = array_filter(
            array_merge([storage_path('logs/laravel.log')], glob(storage_path('logs/laravel-*.log')) ?: []),
            'is_file',
        );
        if ($candidates === []) {
            return null;
        }
        // @-suppress the stat: a candidate rotated away between is_file() and here must degrade, not 500 the page.
        usort($candidates, fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));

        return $candidates[0];
    }

    /**
     * Last $n non-empty lines, reading at most the trailing 256 KB so a huge log never loads into memory.
     *
     * @return list<string>
     */
    private function tailLines(string $file, int $n): array
    {
        $maxBytes = 256 * 1024;
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return [];
        }
        if ((int) @filesize($file) > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh); // drop the partial first line
        }
        $content = (string) stream_get_contents($fh);
        fclose($fh);

        $lines = array_values(array_filter(
            preg_split('/\r?\n/', $content) ?: [],
            fn (string $l): bool => trim($l) !== '',
        ));

        return array_slice($lines, -$n);
    }

    /**
     * Mask the secret shapes a Laravel log realistically carries before display. Deliberately over-redacts on
     * ambiguity (a shown-but-masked secret is safe; a leaked one is not). Order matters: the Authorization rule
     * masks the whole credential first, so the generic keyword rule below can't half-mask a `Bearer <jwt>` and
     * leave the token exposed.
     */
    private function redact(string $line): string
    {
        $patterns = [
            // Authorization / Proxy-Authorization — mask the ENTIRE value (scheme + token) to a closing quote or EOL.
            '/((?:proxy-)?authorization["\']?\s*[:=]\s*["\']?)[^"\'\r\n]+/i' => '$1[redacted]',
            // Bare auth schemes not behind an Authorization label.
            '/\b(?:bearer|basic)\s+[A-Za-z0-9._\-+\/=]+/i' => '[redacted]',
            // JWTs (three base64url segments) anywhere in the line.
            '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/' => '[redacted]',
            // key=value / key: value / "key":"value" secrets — a broad keyword set.
            '/((?:password|passwd|pwd|secret|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key|encryption[_-]?key|app[_-]?key|auth[_-]?token|token|dsn)["\']?\s*[=:>]+\s*["\']?)[^\s"\',;&]+/i' => '$1[redacted]',
            // Credentials embedded in a URL/DSN: scheme://user:PASS@host → mask PASS (spares normal URLs + ports).
            '/([a-z][a-z0-9+.\-]*:\/\/[^:\/\s@]+:)[^@\/\s]+(@)/i' => '$1[redacted]$2',
            // Session / remember-me / CSRF cookie values (live auth material).
            '/\b(laravel_session|remember_web_[a-z0-9]+|xsrf-token)(["\']?\s*[=:]\s*["\']?)[^\s;"\',]+/i' => '$1$2[redacted]',
            // Known provider secret token prefixes (Stripe, GitHub, GitLab, Slack, xAI, OpenAI).
            '/\b(?:sk|pk|rk|whsec|xox[baprs]|ghp|gho|ghs|ghu|glpat|xai)[_-][A-Za-z0-9_\-]{16,}/i' => '[redacted]',
            // AWS access-key IDs (fixed prefix + 16 chars, no separator) and Google / GitHub fine-grained keys.
            '/\b(?:AKIA|ASIA|AROA|AIDA)[A-Z0-9]{16}\b/' => '[redacted]',
            '/\bAIza[A-Za-z0-9_\-]{20,}/' => '[redacted]',
            '/\bgithub_pat_[A-Za-z0-9_]{20,}/' => '[redacted]',
            // APP_KEY / base64 secret blobs: the base64: prefix, or a padded 40+ char base64 run (padding spares paths).
            '/\bbase64:[A-Za-z0-9+\/=]{16,}/' => 'base64:[redacted]',
            '/\b[A-Za-z0-9+\/]{40,}={1,2}/' => '[redacted]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $line);
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
        abort_unless($u->canDo('admin.system.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-6" dusk="acp-maintenance">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    {{-- Housekeeping actions. --}}
    <x-ui.card>
        <div class="space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-ink">Caches &amp; counters</h2>
                <p class="mt-1 text-sm text-ink-muted max-w-2xl">
                    Clear the config, route and event caches — a safe rebuild that keeps the ban list, rate-limit
                    and queue state intact (compiled templates recompile automatically when they change). Or queue
                    a self-heal that recomputes forum, topic, and member post counts from the live posts if any drift.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="button" variant="ghost" wire:click="clearCaches"
                             wire:loading.attr="disabled" wire:target="clearCaches" dusk="acp-clear-caches">
                    <span wire:loading.remove wire:target="clearCaches">Clear compiled caches</span>
                    <span wire:loading wire:target="clearCaches">Clearing…</span>
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="rebuildCounters"
                             wire:loading.attr="disabled" wire:target="rebuildCounters" dusk="acp-rebuild-counters">
                    <span wire:loading.remove wire:target="rebuildCounters">Rebuild counters</span>
                    <span wire:loading wire:target="rebuildCounters">Queuing…</span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    {{-- Mail self-test. --}}
    <x-ui.card>
        <form wire:submit="sendTest" class="space-y-3">
            <div>
                <h2 class="text-sm font-semibold text-ink">Send a test email</h2>
                <p class="mt-1 text-sm text-ink-muted">Deliver one message through the configured transport to confirm outbound mail works.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-64 flex-1">
                    <x-ui.input label="Recipient" name="testTo" type="email" wire:model="testTo" placeholder="you@example.com" dusk="acp-mail-to" />
                </div>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="sendTest" dusk="acp-mail-send">
                    <span wire:loading.remove wire:target="sendTest">Send test</span>
                    <span wire:loading wire:target="sendTest">Sending…</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Application log tail (read-only, redacted). --}}
    <x-ui.card>
        <div class="space-y-3">
            <div>
                <h2 class="text-sm font-semibold text-ink">Application log</h2>
                <p class="mt-1 text-sm text-ink-muted">The most recent entries from <code class="font-mono text-xs">storage/logs</code>, secrets redacted. Read-only.</p>
            </div>
            @php($lines = $this->logTail())
            @if ($lines === [])
                <x-ui.empty title="The log is empty" :icon="'<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'1.75\' stroke-linecap=\'round\' stroke-linejoin=\'round\' class=\'h-6 w-6\'><path d=\'M4 5h16\'/><path d=\'M4 12h16\'/><path d=\'M4 19h10\'/></svg>'">
                    Nothing has been logged yet.
                </x-ui.empty>
            @else
                <div class="max-h-96 overflow-auto rounded-md border border-line bg-surface-sunken p-3" dusk="acp-log-tail">
                    <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-ink-muted">@foreach ($lines as $line){{ $line }}
@endforeach</pre>
                </div>
            @endif
        </div>
    </x-ui.card>
</div>
