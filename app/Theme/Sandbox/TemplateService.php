<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

use App\Models\Post;
use App\Models\SiteTemplate;
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

    /** Validate + lint, then store/update the override (kept enabled state, default enabled for a new one). */
    public function save(string $key, string $source): SiteTemplate
    {
        if (! TemplateContract::has($key)) {
            throw new \InvalidArgumentException("Unknown template '{$key}'.");
        }

        $this->lint($source);

        $row = SiteTemplate::query()->firstOrNew(['template_key' => $key]);
        $row->source = $source;
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
     * Defence-in-depth lint, run BEFORE a template can be stored. The engine already cannot execute code and
     * escapes every {{ }} value — this additionally forbids an admin's LITERAL template text from carrying
     * <script>/<style>/handlers/javascript: (and readies the sandbox for lower-trust authors). It also
     * requires the source to PARSE, so an admin can't save a broken template.
     *
     * @throws SandboxException
     */
    public function lint(string $source): void
    {
        // Parse first — rejects malformed tags + un-sandboxable syntax, and guarantees the tags are well-formed
        // for the skeleton strip below (an unclosed/garbled tag can never be stored).
        $error = SandboxRenderer::validate($source);
        if ($error !== null) {
            throw new SandboxException($error);
        }

        // Scan the LITERAL SKELETON — the source with every {{…}} / {%…%} tag removed — NOT the raw source.
        // Dynamic {{ }} output is HTML-escaped at render, so only literal text can introduce raw markup; the
        // skeleton IS that literal text. Stripping the tags collapses split tokens (e.g. `<scr{{ x }}ipt>`),
        // which would otherwise pass a raw stripos() yet reassemble into `<script>` in the unescaped output.
        $skeleton = (string) preg_replace(['/\{\{.*?\}\}/s', '/\{%.*?%\}/s'], '', $source);

        foreach (['<script', '</script', '<style', '</style', '<iframe', '<object', '<embed', '<base', '<meta', '<link', 'javascript:'] as $forbidden) {
            if (stripos($skeleton, $forbidden) !== false) {
                throw new SandboxException('A template may not contain "'.$forbidden.'".');
            }
        }
        if (preg_match('/\son[a-z]+\s*=/i', $skeleton) === 1) {
            throw new SandboxException('A template may not contain inline event handlers (on…=).');
        }
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
