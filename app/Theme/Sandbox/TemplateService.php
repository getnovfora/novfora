<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

use App\Models\Post;
use App\Models\SiteTemplate;
use App\Models\SiteTemplateHook;
use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;

/**
 * The audited authority for sandbox templates (ADR-0038): builds the (pure-array) render context, renders an
 * overridable template by key through the sandbox, and is the only writer of admin overrides (validated +
 * linted first). A render NEVER touches a model object inside the sandbox — every value handed to
 * SandboxRenderer is a scalar or array, which is what makes the sandbox safe.
 */
final class TemplateService
{
    /** The cached map of enabled hook fragments (hook_key → ordered list) — one read per request. */
    public const HOOKS_CACHE_KEY = 'novfora:tpl:hooks';

    /** Aggregate output cap for ALL fragments rendered at one anchor (defence against a many-fragment anchor). */
    private const HOOKS_MAX_OUTPUT = 262144;

    /** @var array<string, list<array{id:int,name:string,source:string}>>|null per-request memo */
    private ?array $hooksMemo = null;

    public function __construct(private readonly SandboxRenderer $renderer) {}

    /** Render an overridable template by key, or '' when it isn't enabled / doesn't exist / fails to render. */
    public function render(string $key, array $extra = []): string
    {
        if (! TemplateContract::has($key)) {
            return '';
        }

        $row = SiteTemplate::query()->where('template_key', $key)->first();
        if (! $row instanceof SiteTemplate || ! $row->is_enabled) {
            return '';
        }

        try {
            return $this->renderer->render((string) $row->source, $this->globalContext() + $extra);
        } catch (SandboxException) {
            return ''; // a broken/over-limit template degrades to nothing — never breaks the page
        }
    }

    /** The source the editor shows: the admin's override if present, else the shipped default. */
    public function source(string $key): string
    {
        $row = SiteTemplate::query()->where('template_key', $key)->first();

        return $row instanceof SiteTemplate ? (string) $row->source : TemplateContract::default($key);
    }

    public function isOverridden(string $key): bool
    {
        return SiteTemplate::query()->where('template_key', $key)->exists();
    }

    /** Validate + lint, then store/update the override (kept enabled state, default enabled for a new one).
     *  Every explicit save stamps base_source = the CURRENT shipped default (U11): the admin's edit is, by
     *  definition, derived from the default they saw — that snapshot is diff3's base on the next release. */
    public function save(string $key, string $source): SiteTemplate
    {
        if (! TemplateContract::has($key)) {
            throw new \InvalidArgumentException("Unknown template '{$key}'.");
        }

        $this->lint($source);

        $row = SiteTemplate::query()->firstOrNew(['template_key' => $key]);
        $row->source = $source;
        $row->base_source = TemplateContract::default($key);
        $row->merge_state = 'current';
        $row->merged_at = null;
        if (! $row->exists) {
            $row->is_enabled = true;
        }
        $row->save();

        Audit::log('template.saved', $row, ['key' => $key]);

        return $row;
    }

    /** Reset an override back to the shipped default (and enable it). */
    public function revert(string $key): SiteTemplate
    {
        return $this->save($key, TemplateContract::default($key));
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $row = SiteTemplate::query()->where('template_key', $key)->first();
        if ($row instanceof SiteTemplate) {
            $row->update(['is_enabled' => $enabled]);
            Audit::log($enabled ? 'template.enabled' : 'template.disabled', $row, ['key' => $key]);
        }
    }

    /** Remove the override entirely (back to stock — nothing renders). */
    public function remove(string $key): void
    {
        $row = SiteTemplate::query()->where('template_key', $key)->first();
        if ($row instanceof SiteTemplate) {
            Audit::log('template.removed', $row, ['key' => $key]);
            $row->delete();
        }
    }

    /**
     * Render every enabled fragment attached to a hook anchor (U11), in position order, each through the
     * SAME sandbox as template overrides. A fragment that fails degrades to '' in isolation — one broken
     * fragment never takes down its siblings or the page. Unknown anchors render nothing.
     */
    public function renderHooks(string $hookKey, array $extra = []): string
    {
        if (! TemplateContract::hasHook($hookKey)) {
            return '';
        }

        $fragments = $this->hooksMap()[$hookKey] ?? [];
        if ($fragments === []) {
            return '';
        }

        $context = $this->globalContext() + $extra;
        $out = '';
        foreach ($fragments as $fragment) {
            try {
                $out .= $this->renderer->render($fragment['source'], $context);
            } catch (SandboxException) {
                // isolated failure — skip this fragment
            }
            // Aggregate cap across all fragments at one anchor (each fragment is already bounded to the
            // renderer's per-render MAX_OUTPUT; this bounds a many-fragments anchor, esp. the per-post one).
            if (strlen($out) > self::HOOKS_MAX_OUTPUT) {
                return substr($out, 0, self::HOOKS_MAX_OUTPUT);
            }
        }

        return $out;
    }

    /** Lint + store a hook fragment (new or edited). The anchor must exist in the contract. */
    public function saveHook(?int $id, string $hookKey, string $name, string $source, int $position = 0): SiteTemplateHook
    {
        if (! TemplateContract::hasHook($hookKey)) {
            throw new \InvalidArgumentException("Unknown template hook '{$hookKey}'.");
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('A fragment name is required.');
        }

        $this->lint($source);

        $row = $id !== null ? SiteTemplateHook::query()->findOrFail($id) : new SiteTemplateHook;
        $row->fill(['hook_key' => $hookKey, 'name' => $name, 'source' => $source, 'position' => $position]);
        if (! $row->exists) {
            $row->is_enabled = true;
        }
        $row->save();
        $this->invalidateHooks();

        Audit::log('template.hook.saved', $row, ['hook' => $hookKey, 'name' => $name]);

        return $row;
    }

    public function setHookEnabled(int $id, bool $enabled): void
    {
        $row = SiteTemplateHook::query()->findOrFail($id);
        $row->update(['is_enabled' => $enabled]);
        $this->invalidateHooks();
        Audit::log($enabled ? 'template.hook.enabled' : 'template.hook.disabled', $row, ['hook' => $row->hook_key]);
    }

    public function removeHook(int $id): void
    {
        $row = SiteTemplateHook::query()->find($id);
        if ($row instanceof SiteTemplateHook) {
            Audit::log('template.hook.removed', $row, ['hook' => $row->hook_key, 'name' => $row->name]);
            $row->delete();
            $this->invalidateHooks();
        }
    }

    /** Drop the cached hook map (called on every hook write). */
    public function invalidateHooks(): void
    {
        $this->hooksMemo = null;
        Cache::forget(self::HOOKS_CACHE_KEY);
    }

    /**
     * The enabled hook fragments grouped by anchor, cached forever + memoised — the render path costs zero
     * queries steady-state (the query budgets depend on this). Defensive pre-install, like the style themes.
     *
     * @return array<string, list<array{id:int,name:string,source:string}>>
     */
    private function hooksMap(): array
    {
        if ($this->hooksMemo !== null) {
            return $this->hooksMemo;
        }

        try {
            $cached = Cache::get(self::HOOKS_CACHE_KEY);
            if (is_array($cached)) {
                return $this->hooksMemo = $cached;
            }

            $map = [];
            $rows = SiteTemplateHook::query()->where('is_enabled', true)
                ->orderBy('position')->orderBy('id')
                ->get(['id', 'hook_key', 'name', 'source']);
            foreach ($rows as $row) {
                $map[(string) $row->hook_key][] = [
                    'id' => (int) $row->id, 'name' => (string) $row->name, 'source' => (string) $row->source,
                ];
            }
            Cache::forever(self::HOOKS_CACHE_KEY, $map);

            return $this->hooksMemo = $map;
        } catch (\Throwable) {
            return []; // pre-install / mid-migration — render nothing, don't poison the cache
        }
    }

    /** Literal-text markers forbidden in a stored template (raw-emitted text only — {{ }} values are escaped). */
    private const FORBIDDEN_SUBSTRINGS = [
        '<script', '</script', '<style', '</style', '<iframe', '<object', '<embed',
        '<base', '<meta', '<link', 'javascript:', 'vbscript:', 'data:text/html',
    ];

    /**
     * Defence-in-depth lint, run BEFORE a template can be stored. The engine already cannot execute code and
     * escapes every {{ }} value — this additionally forbids an admin's LITERAL template text from carrying
     * <script>/<style>/handlers/javascript:/etc. (and readies the sandbox for lower-trust authors). It also
     * requires the source to PARSE, so an admin can't save a broken template.
     *
     * The scanned text is derived from the REAL parsed AST — the exact TEXT nodes the renderer emits raw —
     * NOT a regex approximation. The prior regex skeleton (`{{…}}`/`{%…%}` strip) did not agree with the
     * lexer's tag boundaries: a `{{` inside a string literal within a {% %} tag let the regex over-delete
     * real literal text, so a literal <script> could survive the scan yet render raw (apex finding H1).
     * Scanning the AST text nodes is sound by construction.
     *
     * @throws SandboxException
     */
    public function lint(string $source): void
    {
        // Parse with the real lexer — throws on malformed / un-sandboxable syntax (so a broken template can
        // never be stored) and yields the exact nodes the renderer will execute.
        $nodes = SandboxParser::parse($source);

        // Two scans over the raw-emitted literal text, both collapsing tokens split across interpolations
        // (the ADR-0038 split-token lesson): (gap) interpolations contribute nothing → rejoins a split TAG
        // like `<scr{{ x }}ipt>`; (filler) each interpolation contributes one word char → rejoins a split
        // ATTRIBUTE name like `on{{ "error" }}=` into `ona=`. A conditional's branches are concatenated —
        // conservative: text that COULD assemble into a forbidden token across branches is refused.
        foreach ([$this->literalText($nodes, false), $this->literalText($nodes, true)] as $scan) {
            foreach (self::FORBIDDEN_SUBSTRINGS as $forbidden) {
                if (stripos($scan, $forbidden) !== false) {
                    throw new SandboxException('A template may not contain "'.$forbidden.'".');
                }
            }
            // An event handler in ANY HTML attribute position — separated from the tag by whitespace OR a
            // slash (both are valid HTML attribute separators; the slash form `<img/onerror=>` bypassed the
            // old whitespace-only regex — apex finding H2).
            if (preg_match('/[\s\/]on[a-z][a-z0-9_-]*\s*=/i', $scan) === 1) {
                throw new SandboxException('A template may not contain inline event handlers (on…=).');
            }
        }
    }

    /**
     * Concatenate the raw-emitted LITERAL text of a parsed AST, recursing into if/for bodies. With
     * $filler=false an interpolation contributes nothing (rejoins a split tag); with $filler=true it
     * contributes one word char (rejoins a split attribute name).
     *
     * @param  list<array<string,mixed>>  $nodes
     */
    private function literalText(array $nodes, bool $filler): string
    {
        $out = '';
        foreach ($nodes as $node) {
            switch ($node['t'] ?? '') {
                case 'text':
                    $out .= (string) ($node['v'] ?? '');
                    break;
                case 'out':
                    $out .= $filler ? 'x' : '';
                    break;
                case 'if':
                    foreach ($node['branches'] ?? [] as $branch) {
                        $out .= $this->literalText($branch['body'] ?? [], $filler);
                    }
                    $out .= $this->literalText($node['else'] ?? [], $filler);
                    break;
                case 'for':
                    $out .= $this->literalText($node['body'] ?? [], $filler);
                    break;
            }
        }

        return $out;
    }

    /**
     * The GLOBAL render context — all pure scalars/arrays. Per-instance data (e.g. the current topic) is
     * merged on top by the caller. The expensive counts are cached for a minute (shared, no PII).
     *
     * @return array<string,mixed>
     */
    public function globalContext(): array
    {
        /** @var array{members:int,topics:int,posts:int} $stats */
        $stats = Cache::remember('novfora:tpl:stats', now()->addMinute(), fn (): array => [
            'members' => (int) User::query()->where('status', 'active')->count(),
            'topics' => (int) Topic::query()->count(),
            'posts' => (int) Post::query()->count(),
        ]);

        $user = auth()->user();

        return [
            'site' => [
                'name' => (string) config('app.name', 'NovFora'),
                'description' => (string) config('app.tagline', ''),
            ],
            'user' => [
                'is_guest' => $user === null,
                'username' => $user instanceof User ? (string) $user->username : '',
            ],
            'stats' => $stats,
        ];
    }
}
