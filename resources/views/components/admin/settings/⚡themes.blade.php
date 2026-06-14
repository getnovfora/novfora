<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\SiteTheme;
use App\Models\User;
use App\Permissions\Scope;
use App\Theme\StyleThemeManager;
use Livewire\Component;

/**
 * Admin → Settings → Themes (the visual theme editor). Create / edit / activate / delete DB-backed style
 * themes — an AA-safe accent colour plus optional custom CSS — WITHOUT touching the filesystem (distinct from
 * the filesystem child-theme dropdown on the Appearance page, which overrides Blade views). All domain rules
 * and the single-active invariant live in StyleThemeManager; like every admin SFC the authorization is
 * re-asserted in mount() AND every action, because Livewire actions reach the component via livewire/update
 * with no route middleware.
 */
new class extends Component
{
    public bool $showForm = false;

    public ?int $formId = null;

    public string $name = '';

    public string $accentColor = '';

    /** @var array<string,string> token-key => value (see App\Theme\ThemeApi::editableTokens()) */
    public array $tokens = [];

    public string $customCss = '';

    public string $headerHtml = '';

    public string $footerHtml = '';

    public ?int $deleteId = null;

    public ?string $message = null;

    public string $messageVariant = 'info';

    public function mount(): void
    {
        $this->ensureAdmin();
    }

    public function newTheme(): void
    {
        $this->ensureAdmin();
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->ensureAdmin();
        $theme = SiteTheme::findOrFail($id);
        $this->formId = $theme->id;
        $this->name = (string) $theme->name;
        $this->accentColor = (string) ($theme->accent_color ?? '');
        $this->tokens = is_array($theme->tokens) ? $theme->tokens : [];
        $this->customCss = (string) ($theme->custom_css ?? '');
        $this->headerHtml = (string) ($theme->header_html ?? '');
        $this->footerHtml = (string) ($theme->footer_html ?? '');
        $this->deleteId = null;
        $this->showForm = true;
    }

    public function save(StyleThemeManager $manager): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'accentColor' => ['nullable', 'string', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'customCss' => ['nullable', 'string', 'max:20000'],
            'headerHtml' => ['nullable', 'string', 'max:20000'],
            'footerHtml' => ['nullable', 'string', 'max:20000'],
        ]);

        $payload = [
            'name' => $data['name'],
            'accent_color' => $data['accentColor'] ?? null,
            'tokens' => $this->tokens, // StyleThemeManager::cleanTokens() strict-validates each value
            'custom_css' => $data['customCss'] ?? null,
            'header_html' => $this->headerHtml,  // sanitised through the post allowlist on save
            'footer_html' => $this->footerHtml,
        ];

        if ($this->formId === null) {
            $theme = $manager->create($payload);
            $this->flash("Created theme “{$theme->name}”.", 'success');
        } else {
            $theme = $manager->update(SiteTheme::findOrFail($this->formId), $payload);
            $this->flash("Saved theme “{$theme->name}”.", 'success');
        }
        $this->cancelForm();
    }

    public function activate(int $id, StyleThemeManager $manager): void
    {
        $this->ensureAdmin();
        $manager->activate(SiteTheme::findOrFail($id));
        $this->flash('Theme activated. Reload a page to see it.', 'success');
    }

    public function deactivate(StyleThemeManager $manager): void
    {
        $this->ensureAdmin();
        $manager->deactivate();
        $this->flash('Reverted to the built-in default look.', 'success');
    }

    public function askDelete(int $id): void
    {
        $this->ensureAdmin();
        $this->deleteId = $id;
        $this->showForm = false;
        $this->message = null;
    }

    public function cancelDelete(): void
    {
        $this->deleteId = null;
    }

    public function delete(StyleThemeManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->deleteId === null) {
            return;
        }
        $theme = SiteTheme::findOrFail($this->deleteId);
        $manager->delete($theme);
        $this->flash("Deleted “{$theme->name}”.", 'success');
        $this->deleteId = null;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /** @return list<SiteTheme> */
    public function rows(): array
    {
        $this->ensureAdmin();

        return SiteTheme::query()->orderByDesc('is_active')->orderBy('name')->get()->all();
    }

    /**
     * The live token preview: each token's EFFECTIVE value (draft override or built-in default) plus the
     * WCAG contrast ratios the editor badges. Recomputed on every wire:model.live edit — keeps the Blade
     * dumb (no arrow functions / inline logic that the compiler trips over).
     *
     * @return array{eff: array<string,string>, badges: list<array{label:string,ratio:float,pass:bool}>}
     */
    public function tokenPreview(): array
    {
        $eff = [];
        foreach (\App\Theme\ThemeApi::editableTokens() as $key => $meta) {
            $v = isset($this->tokens[$key]) ? trim((string) $this->tokens[$key]) : '';
            $eff[$key] = $v !== '' ? $v : $meta['default'];
        }

        $ratio = static fn (string $a, string $b): float => \App\Support\AccentPalette::contrastRatio($a, $b) ?? 0.0;
        $badge = static fn (string $label, float $r): array => ['label' => $label, 'ratio' => $r, 'pass' => $r >= 4.5];

        return [
            'eff' => $eff,
            'badges' => [
                $badge('Text on bg', $ratio($eff['ink'], $eff['surface'])),
                $badge('Muted on bg', $ratio($eff['ink_muted'], $eff['surface'])),
                $badge('Text on card', $ratio($eff['ink'], $eff['surface_raised'])),
            ],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['formId', 'name', 'accentColor', 'tokens', 'customCss', 'headerHtml', 'footerHtml']);
        $this->resetErrorBag();
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
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<div class="space-y-5" dusk="acp-themes">
    @if ($message)
        <x-ui.alert :variant="$messageVariant">{{ $message }}</x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-ink-muted max-w-2xl">
            Create visual themes — an accent colour plus optional custom CSS — and activate one for the whole
            site. Themes are stored in the database and applied instantly; no files to edit. The accent stays
            AA-contrast in both light and dark. (For deeper template overrides, drop a child theme in the
            themes directory — it appears in the <strong>Appearance</strong> page's theme dropdown.)
        </p>
        <x-ui.button type="button" size="sm" wire:click="newTheme" dusk="acp-new-theme">
            <x-ui.icon name="plus" class="h-4 w-4" /> New theme
        </x-ui.button>
    </div>

    {{-- Create / edit form. --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <h2 class="text-sm font-semibold text-ink">{{ $formId ? 'Edit theme' : 'New theme' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Name" name="name" wire:model="name" required maxlength="60" dusk="acp-theme-name" />
                    <x-ui.input label="Accent colour" name="accentColor" wire:model.live="accentColor" placeholder="#4f46e5"
                                hint="Hex, e.g. #4f46e5. Blank = inherit the built-in indigo." />
                </div>

                @php($previewAccent = \App\Support\AccentPalette::for($accentColor))
                @if ($previewAccent)
                    <p class="flex items-center gap-2 text-sm text-ink-muted">
                        <span class="inline-block h-4 w-4 rounded-full" style="background: {{ $previewAccent['light']['accent'] }};" aria-hidden="true"></span>
                        Accent preview
                    </p>
                @endif

                {{-- Colours & tokens (Theme Studio 1.1): override the core design tokens, AA-checked live. --}}
                @php($preview = $this->tokenPreview())
                <div class="rounded-md border border-line p-4 space-y-4" dusk="acp-theme-tokens">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-ink">Colours &amp; tokens</h3>
                        <span class="text-xs text-ink-subtle">Light palette · dark stays tuned · blank = built-in</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach (\App\Theme\ThemeApi::editableTokens() as $key => $meta)
                            <div>
                                <label for="token-{{ $key }}" class="block text-xs font-medium text-ink-muted">{{ $meta['label'] }}</label>
                                <div class="mt-1 flex items-center gap-2">
                                    @if ($meta['type'] === 'color')
                                        <span class="inline-block h-6 w-6 shrink-0 rounded border border-line" style="background: {{ $preview['eff'][$key] }}" aria-hidden="true"></span>
                                    @endif
                                    <input id="token-{{ $key }}" type="text" wire:model.live="tokens.{{ $key }}"
                                           placeholder="{{ $meta['default'] }}" autocomplete="off" spellcheck="false"
                                           class="w-full rounded-md border border-line bg-surface px-2 py-1 font-mono text-xs text-ink" />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Live preview + WCAG AA badges (server-computed; updates as you type). --}}
                    <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-stretch">
                        <div class="rounded-md border p-4"
                             style="background: {{ $preview['eff']['surface'] }}; border-color: {{ $preview['eff']['line'] }}; border-radius: {{ $preview['eff']['radius'] }}"
                             dusk="acp-theme-preview">
                            <p class="text-sm font-semibold" style="color: {{ $preview['eff']['ink'] }}">The quick brown fox</p>
                            <p class="text-xs" style="color: {{ $preview['eff']['ink_muted'] }}">Muted secondary text jumps over the lazy dog.</p>
                            <span class="mt-2 inline-block rounded px-2 py-1 text-xs font-medium"
                                  style="background: {{ $preview['eff']['surface_raised'] }}; color: {{ $preview['eff']['ink'] }}; border-radius: {{ $preview['eff']['radius'] }}">Raised chip</span>
                        </div>
                        <div class="space-y-1 text-xs">
                            @foreach ($preview['badges'] as $b)
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-ink-muted">{{ $b['label'] }}</span>
                                    <span class="font-mono {{ $b['pass'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format($b['ratio'], 1) }}:1 {{ $b['pass'] ? '✓' : '✗' }}</span>
                                </div>
                            @endforeach
                            <p class="pt-1 text-ink-subtle">AA needs 4.5:1 for text.</p>
                        </div>
                    </div>
                </div>

                <x-ui.textarea label="Custom CSS" name="customCss" wire:model="customCss" rows="8"
                               hint="Optional. Plain CSS targeting the design tokens, e.g. :root{ --radius-md: 2px; }. Any style close-tag is stripped before saving."
                               class="font-mono text-xs" />

                {{-- Custom header / footer HTML (Theme Studio 1.2) — sanitised through the post allowlist on save. --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.textarea label="Custom header HTML" name="headerHtml" wire:model="headerHtml" rows="5"
                                   hint="Shown as a banner below the site header. Sanitised like a post — scripts & styles are stripped."
                                   class="font-mono text-xs" />
                    <x-ui.textarea label="Custom footer HTML" name="footerHtml" wire:model="footerHtml" rows="5"
                                   hint="Shown in the footer above the credit line. Sanitised like a post — scripts & styles are stripped."
                                   class="font-mono text-xs" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" dusk="acp-theme-save">
                        <span wire:loading.remove wire:target="save">{{ $formId ? 'Save changes' : 'Create theme' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancelForm">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Theme list. --}}
    <x-ui.card flush>
        <ul class="divide-y divide-line">
            @forelse ($this->rows() as $theme)
                <li>
                    @php($swatch = ($sw = \App\Support\AccentPalette::for($theme->accent_color)) ? $sw['light']['accent'] : 'transparent')
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5 text-sm">
                        <span class="inline-block h-4 w-4 shrink-0 rounded-full border border-line"
                              style="background: {{ $swatch }};" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ $theme->name }}</span>
                        @if ($theme->is_active)
                            <x-ui.badge variant="accent">Active</x-ui.badge>
                        @endif
                        <div class="flex flex-wrap items-center gap-1">
                            @if ($theme->is_active)
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="deactivate">Deactivate</x-ui.button>
                            @else
                                <x-ui.button type="button" variant="subtle" size="sm" wire:click="activate({{ $theme->id }})" dusk="acp-theme-activate-{{ $theme->id }}">Activate</x-ui.button>
                            @endif
                            <x-ui.button type="button" variant="ghost" size="sm" icon wire:click="edit({{ $theme->id }})" title="Edit" dusk="acp-theme-edit-{{ $theme->id }}">
                                <x-ui.icon name="pencil" class="h-4 w-4" />
                            </x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" size="sm" icon wire:click="askDelete({{ $theme->id }})" title="Delete">
                                <x-ui.icon name="trash" class="h-4 w-4" />
                            </x-ui.button>
                        </div>
                    </div>

                    @if ($deleteId === $theme->id)
                        <div class="border-t border-line bg-surface-sunken px-4 py-4 sm:px-5">
                            <x-ui.alert variant="warn" class="mb-3">Delete “{{ $theme->name }}”? This can't be undone.</x-ui.alert>
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button type="button" variant="danger" wire:click="delete">Delete</x-ui.button>
                                <x-ui.button type="button" variant="ghost" wire:click="cancelDelete">Cancel</x-ui.button>
                            </div>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-4 py-6 sm:px-5 text-sm text-ink-subtle">No themes yet. Create one to get started — until then the built-in default look applies.</li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
