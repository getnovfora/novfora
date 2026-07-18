<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\SiteTheme;
use App\Models\User;
use App\Permissions\Scope;
use App\Theme\StyleThemeManager;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    public bool $showForm = false;

    public ?int $formId = null;

    /** Temporary Livewire uploads (logo / favicon / background). */
    public $logoUpload = null;

    public $faviconUpload = null;

    public $backgroundUpload = null;

    /** Currently-stored asset URLs (shown while editing). @var array<string,?string> */
    public array $assetUrls = ['logo' => null, 'favicon' => null, 'background' => null];

    public string $name = '';

    /** U10: the parent style ('' = none) — inheritance resolves child-wins through StyleThemeManager. */
    public string $parentId = '';

    /** U10: offered in the member style chooser. */
    public bool $userSelectable = false;

    public string $accentColor = '';

    /** @var array<string,string> token-key => LIGHT value (see App\Theme\ThemeApi::editableTokens()) */
    public array $tokens = [];

    /** @var array<string,string> token-key => DARK value (U9; blank = keep the tuned built-in dark) */
    public array $tokensDark = [];

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
        $this->parentId = $theme->parent_id !== null ? (string) $theme->parent_id : '';
        $this->userSelectable = (bool) $theme->is_user_selectable;
        $this->accentColor = (string) ($theme->accent_color ?? '');
        $this->tokens = is_array($theme->tokens) ? $theme->tokens : [];
        $this->tokensDark = is_array($theme->tokens_dark) ? $theme->tokens_dark : [];
        $this->customCss = (string) ($theme->custom_css ?? '');
        $this->headerHtml = (string) ($theme->header_html ?? '');
        $this->footerHtml = (string) ($theme->footer_html ?? '');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $this->assetUrls = [
            'logo' => $theme->logo_path ? $disk->url($theme->logo_path) : null,
            'favicon' => $theme->favicon_path ? $disk->url($theme->favicon_path) : null,
            'background' => $theme->background_path ? $disk->url($theme->background_path) : null,
        ];
        $this->reset(['logoUpload', 'faviconUpload', 'backgroundUpload']);
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
            'logoUpload' => ['nullable', 'image', 'max:2048'],
            'faviconUpload' => ['nullable', 'image', 'max:512'],
            'backgroundUpload' => ['nullable', 'image', 'max:4096'],
        ]);

        $payload = [
            'name' => $data['name'],
            'parent_id' => $this->parentId !== '' ? (int) $this->parentId : null,
            'is_user_selectable' => $this->userSelectable,
            'accent_color' => $data['accentColor'] ?? null,
            'tokens' => $this->tokens, // StyleThemeManager::cleanTokens() strict-validates each value
            'tokens_dark' => $this->tokensDark,
            'custom_css' => $data['customCss'] ?? null,
            'header_html' => $this->headerHtml,  // sanitised through the post allowlist on save
            'footer_html' => $this->footerHtml,
        ];

        try {
            if ($this->formId === null) {
                $theme = $manager->create($payload);
                $this->flash("Created theme “{$theme->name}”.", 'success');
            } else {
                $theme = $manager->update(SiteTheme::findOrFail($this->formId), $payload);
                $this->flash("Saved theme “{$theme->name}”.", 'success');
            }
        } catch (\InvalidArgumentException $e) {
            // Tree guards (self-parent / cycle / depth / missing parent) surface as a field error.
            $this->addError('parentId', $e->getMessage());

            return;
        }

        // Bind any freshly-uploaded assets to the saved theme (validated 'image' above).
        foreach (['logo' => $this->logoUpload, 'favicon' => $this->faviconUpload, 'background' => $this->backgroundUpload] as $kind => $upload) {
            if ($upload !== null) {
                $manager->storeAsset($theme, $kind, $upload);
            }
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
        try {
            $manager->delete($theme);
            $this->flash("Deleted “{$theme->name}”.", 'success');
        } catch (\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), 'warn');
        }
        $this->deleteId = null;
    }

    /** U10: install the shipped presets (idempotent by slug) for installs that predate the seeder. */
    public function installPresets(): void
    {
        $this->ensureAdmin();
        $created = \App\Theme\StylePresets::install();
        $this->flash($created === []
            ? 'The shipped presets are already installed.'
            : 'Installed presets: '.implode(', ', $created).'.', 'success');
    }

    /**
     * The styles eligible as a parent for the form's theme: everything except itself and its descendants
     * (a child of your own subtree would be a cycle).
     *
     * @return list<SiteTheme>
     */
    public function parentOptions(): array
    {
        $all = SiteTheme::query()->orderBy('name')->get();
        if ($this->formId === null) {
            return $all->all();
        }

        // Collect the edited theme's descendant ids (bounded walk).
        $exclude = [$this->formId => true];
        $frontier = [$this->formId];
        for ($i = 0; $i < 5 && $frontier !== []; $i++) {
            $frontier = SiteTheme::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            foreach ($frontier as $id) {
                $exclude[$id] = true;
            }
        }

        return $all->reject(fn (SiteTheme $t) => isset($exclude[$t->id]))->values()->all();
    }

    public function removeAsset(string $kind, StyleThemeManager $manager): void
    {
        $this->ensureAdmin();
        if ($this->formId === null || ! array_key_exists($kind, $this->assetUrls)) {
            return;
        }
        $manager->clearAsset(SiteTheme::findOrFail($this->formId), $kind);
        $this->assetUrls[$kind] = null;
        $this->flash('Removed the '.$kind.'.', 'success');
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

        return SiteTheme::query()->with('parent')->orderByDesc('is_active')->orderBy('name')->get()->all();
    }

    /**
     * The live token preview: each token's EFFECTIVE value per colour mode (draft override or built-in
     * default) plus the WCAG contrast ratios the editor badges, and the registry grouped for display.
     * Recomputed on every wire:model.live edit — keeps the Blade dumb (no arrow functions / inline logic
     * that the compiler trips over).
     *
     * @return array{
     *   eff: array<string,string>,
     *   effDark: array<string,string>,
     *   badges: list<array{label:string,ratio:float,pass:bool}>,
     *   badgesDark: list<array{label:string,ratio:float,pass:bool}>,
     *   groups: array<string, array<string, array{var:string,label:string,group:string,type:string,default:string,dark_default:?string}>>,
     * }
     */
    public function tokenPreview(): array
    {
        $registry = \App\Theme\ThemeApi::editableTokens();

        $eff = [];
        $effDark = [];
        foreach ($registry as $key => $meta) {
            $v = isset($this->tokens[$key]) ? trim((string) $this->tokens[$key]) : '';
            $eff[$key] = $v !== '' ? $v : $meta['default'];

            $dv = isset($this->tokensDark[$key]) ? trim((string) $this->tokensDark[$key]) : '';
            // A length token has no dark variant (dark_default null) — the light effective value applies.
            $effDark[$key] = $dv !== '' ? $dv : ($meta['dark_default'] ?? $eff[$key]);
        }

        $groups = [];
        foreach (\App\Theme\ThemeApi::tokenGroups() as $group) {
            $groups[$group] = [];
        }
        foreach ($registry as $key => $meta) {
            $groups[$meta['group']][$key] = $meta;
        }

        $ratio = static fn (string $a, string $b): float => \App\Support\AccentPalette::contrastRatio($a, $b) ?? 0.0;
        $badge = static fn (string $label, float $r): array => ['label' => $label, 'ratio' => $r, 'pass' => $r >= 4.5];

        return [
            'eff' => $eff,
            'effDark' => $effDark,
            'groups' => $groups,
            'badges' => [
                $badge('Text on bg', $ratio($eff['ink'], $eff['surface'])),
                $badge('Muted on bg', $ratio($eff['ink_muted'], $eff['surface'])),
                $badge('Text on card', $ratio($eff['ink'], $eff['surface_raised'])),
            ],
            'badgesDark' => [
                $badge('Text on bg', $ratio($effDark['ink'], $effDark['surface'])),
                $badge('Muted on bg', $ratio($effDark['ink_muted'], $effDark['surface'])),
                $badge('Text on card', $ratio($effDark['ink'], $effDark['surface_raised'])),
            ],
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['formId', 'name', 'parentId', 'userSelectable', 'accentColor', 'tokens', 'tokensDark',
            'customCss', 'headerHtml', 'footerHtml', 'logoUpload', 'faviconUpload', 'backgroundUpload', 'assetUrls']);
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
        <div class="flex items-center gap-2">
            <x-ui.button type="button" variant="subtle" size="sm" wire:click="installPresets" dusk="acp-install-presets">
                Install presets
            </x-ui.button>
            <x-ui.button type="button" size="sm" wire:click="newTheme" dusk="acp-new-theme">
                <x-ui.icon name="plus" class="h-4 w-4" /> New theme
            </x-ui.button>
        </div>
    </div>

    {{-- Create / edit form. --}}
    @if ($showForm)
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <h2 class="text-sm font-semibold text-ink">{{ $formId ? 'Edit theme' : 'New theme' }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="Name" name="name" wire:model="name" required maxlength="60" dusk="acp-theme-name" />
                    <x-ui.input label="Accent colour" name="accentColor" wire:model.live="accentColor" placeholder="#245fbb"
                                hint="Hex, e.g. #245fbb. Blank = inherit the built-in Nova Blue." />
                </div>

                {{-- Style tree (U10): parent inheritance + the member chooser flag. --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="theme-parent" class="block text-sm font-medium text-ink">Parent style</label>
                        <select id="theme-parent" wire:model="parentId" dusk="acp-theme-parent"
                                class="mt-1 w-full rounded-md border border-line bg-surface px-2 py-1.5 text-sm text-ink">
                            <option value="">None — a root style</option>
                            @foreach ($this->parentOptions() as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                        @error('parentId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-ink-subtle">A child style inherits every value it doesn't set itself.</p>
                    </div>
                    <div class="flex items-start pt-6">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" wire:model="userSelectable" dusk="acp-theme-selectable"
                                   class="h-4 w-4 rounded border-line text-accent focus:ring-accent">
                            <span class="text-sm font-medium text-ink">Members can choose this style</span>
                        </label>
                    </div>
                </div>

                @php($previewAccent = \App\Support\AccentPalette::for($accentColor))
                @if ($previewAccent)
                    <p class="flex items-center gap-2 text-sm text-ink-muted">
                        <span class="inline-block h-4 w-4 rounded-full" style="background: {{ $previewAccent['light']['accent'] }};" aria-hidden="true"></span>
                        Accent preview
                    </p>
                @endif

                {{-- Style properties (U9): grouped, typed tokens with light + dark values, AA-checked live. --}}
                @php($preview = $this->tokenPreview())
                <div class="rounded-md border border-line p-4 space-y-4" dusk="acp-theme-tokens">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-ink">Style properties</h3>
                        <span class="text-xs text-ink-subtle">Blank = built-in · dark blank = tuned dark stays</span>
                    </div>
                    @foreach ($preview['groups'] as $group => $groupTokens)
                        @if ($groupTokens !== [])
                            <fieldset class="space-y-2">
                                <legend class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ $group }}</legend>
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($groupTokens as $key => $meta)
                                        <div>
                                            <label for="token-{{ $key }}" class="block text-xs font-medium text-ink-muted">{{ $meta['label'] }}</label>
                                            <div class="mt-1 flex items-center gap-2">
                                                @if ($meta['type'] === 'color')
                                                    <span class="inline-block h-6 w-6 shrink-0 rounded border border-line" style="background: {{ $preview['eff'][$key] }}" aria-hidden="true"></span>
                                                @endif
                                                <input id="token-{{ $key }}" type="text" wire:model.live="tokens.{{ $key }}"
                                                       placeholder="{{ $meta['default'] }}" autocomplete="off" spellcheck="false"
                                                       aria-label="{{ $meta['label'] }} — light value"
                                                       class="w-full rounded-md border border-line bg-surface px-2 py-1 font-mono text-xs text-ink" />
                                                @if ($meta['dark_default'] !== null)
                                                    <span class="inline-block h-6 w-6 shrink-0 rounded border border-line" style="background: {{ $preview['effDark'][$key] }}" aria-hidden="true"></span>
                                                    <input id="token-dark-{{ $key }}" type="text" wire:model.live="tokensDark.{{ $key }}"
                                                           placeholder="{{ $meta['dark_default'] }}" autocomplete="off" spellcheck="false"
                                                           aria-label="{{ $meta['label'] }} — dark value"
                                                           class="w-full rounded-md border border-line bg-surface-sunken px-2 py-1 font-mono text-xs text-ink" />
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                    @endforeach
                    <p class="text-xs text-ink-subtle">Each row: light value, then dark value. Colours are hex (<code>#rrggbb</code>); lengths are <code>px/rem/em</code>.</p>

                    {{-- Live preview + WCAG AA badges for BOTH colour modes (server-computed; updates as you type). --}}
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['eff' => ['label' => 'Light', 'badges' => 'badges'], 'effDark' => ['label' => 'Dark', 'badges' => 'badgesDark']] as $mode => $modeMeta)
                            @php($m = $preview[$mode])
                            <div class="space-y-2">
                                <div class="rounded-md border p-4"
                                     style="background: {{ $m['surface'] }}; border-color: {{ $m['line'] }}; border-radius: {{ $m['radius'] }}"
                                     dusk="acp-theme-preview{{ $mode === 'effDark' ? '-dark' : '' }}">
                                    <p class="text-xs font-semibold uppercase tracking-wide" style="color: {{ $m['ink_subtle'] }}">{{ $modeMeta['label'] }}</p>
                                    <p class="text-sm font-semibold" style="color: {{ $m['ink'] }}">The quick brown fox</p>
                                    <p class="text-xs" style="color: {{ $m['ink_muted'] }}">Muted secondary text jumps over the lazy dog.</p>
                                    <span class="mt-2 inline-block rounded px-2 py-1 text-xs font-medium"
                                          style="background: {{ $m['surface_raised'] }}; color: {{ $m['ink'] }}; border-radius: {{ $m['radius'] }}">Raised chip</span>
                                    <span class="mt-2 ml-1 inline-block rounded px-2 py-1 text-xs font-medium"
                                          style="background: {{ $m['success_soft'] }}; color: {{ $m['success_ink'] }}; border-radius: {{ $m['radius'] }}">Success</span>
                                    <span class="mt-2 ml-1 inline-block rounded px-2 py-1 text-xs font-medium"
                                          style="background: {{ $m['danger_soft'] }}; color: {{ $m['danger_ink'] }}; border-radius: {{ $m['radius'] }}">Danger</span>
                                </div>
                                <div class="space-y-1 text-xs">
                                    @foreach ($preview[$modeMeta['badges']] as $b)
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-ink-muted">{{ $b['label'] }}</span>
                                            <span class="font-mono {{ $b['pass'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format($b['ratio'], 1) }}:1 {{ $b['pass'] ? '✓' : '✗' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-ink-subtle">AA needs 4.5:1 for text.</p>
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

                {{-- Theme assets (Theme Studio 1.5): logo / favicon / background, stored on the public disk. --}}
                <div class="rounded-md border border-line p-4 space-y-3" dusk="acp-theme-assets">
                    <h3 class="text-sm font-semibold text-ink">Logo, favicon &amp; background</h3>
                    @if ($formId === null)
                        <p class="text-xs text-ink-subtle">Save the theme first, then re-open it to add images — or pick them now and they’ll be attached on save.</p>
                    @endif
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach (['logo' => ['label' => 'Logo', 'prop' => 'logoUpload', 'hint' => 'Shown in the header · ≤2 MB'], 'favicon' => ['label' => 'Favicon', 'prop' => 'faviconUpload', 'hint' => 'Browser tab icon · ≤512 KB'], 'background' => ['label' => 'Background', 'prop' => 'backgroundUpload', 'hint' => 'Full-page background · ≤4 MB']] as $kind => $meta)
                            <div class="space-y-1">
                                <label class="block text-xs font-medium text-ink-muted">{{ $meta['label'] }}</label>
                                @if ($assetUrls[$kind] ?? null)
                                    <div class="flex items-center gap-2">
                                        <img src="{{ $assetUrls[$kind] }}" alt="" class="h-8 w-8 rounded border border-line object-contain bg-surface">
                                        <button type="button" wire:click="removeAsset('{{ $kind }}')" class="text-xs text-red-600 hover:underline" dusk="acp-theme-remove-{{ $kind }}">Remove</button>
                                    </div>
                                @endif
                                <input type="file" accept="image/*" wire:model="{{ $meta['prop'] }}"
                                       class="block w-full text-xs text-ink-muted file:mr-2 file:rounded file:border-0 file:bg-surface-sunken file:px-2 file:py-1 file:text-xs file:text-ink" />
                                @error($meta['prop']) <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                <p class="text-xs text-ink-subtle">{{ $meta['hint'] }}</p>
                            </div>
                        @endforeach
                    </div>
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
                        <span class="min-w-0 flex-1 truncate font-medium text-ink">
                            {{ $theme->name }}
                            @if ($theme->parent_id !== null)
                                <span class="text-xs text-ink-subtle">· child of {{ $theme->parent?->name ?? '?' }}</span>
                            @endif
                        </span>
                        @if ($theme->is_active)
                            <x-ui.badge variant="accent">Active</x-ui.badge>
                        @endif
                        @if ($theme->is_user_selectable)
                            <x-ui.badge variant="neutral">Selectable</x-ui.badge>
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
