<?php
// SPDX-License-Identifier: Apache-2.0
use App\Models\User;
use App\Permissions\Scope;
use App\Settings\Settings;
use Livewire\Component;

/**
 * Admin → Settings → Appearance (ACP v1, PART 3.6) — SITE-level (distinct from per-user appearance). Active
 * theme (themes-dir scan → ThemeManager), accent colour (emitted as AA-safe CSS variables for both modes),
 * forum width (--layout-max-width token), visitor default colour-mode/density, poster-info position,
 * board-list style, and wordmark text. The topic + board views read poster position / board-list style /
 * width; the layout reads the rest — all presentation switches, no markup-contract breaks.
 */
new class extends Component
{
    public string $activeTheme = '';

    public string $accentColor = '';

    public string $forumWidth = 'standard';

    public string $defaultColorMode = 'auto';

    public string $defaultDensity = 'comfortable';

    public string $posterPosition = 'left';

    public string $boardListStyle = 'info-rich';

    public string $wordmark = '';

    public ?string $saved = null;

    public function mount(Settings $settings): void
    {
        $this->ensureAdmin();
        $this->activeTheme = $settings->string('appearance.active_theme');
        $this->accentColor = $settings->string('appearance.accent_color');
        $this->forumWidth = $settings->string('appearance.forum_width') ?: 'standard';
        $this->defaultColorMode = $settings->string('appearance.default_color_mode') ?: 'auto';
        $this->defaultDensity = $settings->string('appearance.default_density') ?: 'comfortable';
        $this->posterPosition = $settings->string('appearance.poster_position') ?: 'left';
        $this->boardListStyle = $settings->string('appearance.board_list_style') ?: 'info-rich';
        $this->wordmark = $settings->string('appearance.wordmark');
    }

    public function save(Settings $settings): void
    {
        $this->ensureAdmin();
        $data = $this->validate([
            'activeTheme' => ['nullable', 'string', 'max:100', 'in:'.implode(',', array_column($this->themeOptions(), 'value'))],
            'accentColor' => ['nullable', 'string', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'forumWidth' => ['required', 'in:boxed-narrow,standard,wide,full'],
            'defaultColorMode' => ['required', 'in:auto,light,dark'],
            'defaultDensity' => ['required', 'in:comfortable,compact'],
            'posterPosition' => ['required', 'in:top,left,right'],
            'boardListStyle' => ['required', 'in:info-rich,minimal'],
            'wordmark' => ['nullable', 'string', 'max:40'],
        ]);

        $accent = trim((string) ($data['accentColor'] ?? ''));
        if ($accent !== '' && $accent[0] !== '#') {
            $accent = '#'.$accent;
        }

        $settings->set('appearance.active_theme', $data['activeTheme'] ?? '');
        $settings->set('appearance.accent_color', $accent);
        $settings->set('appearance.forum_width', $data['forumWidth']);
        $settings->set('appearance.default_color_mode', $data['defaultColorMode']);
        $settings->set('appearance.default_density', $data['defaultDensity']);
        $settings->set('appearance.poster_position', $data['posterPosition']);
        $settings->set('appearance.board_list_style', $data['boardListStyle']);
        $settings->set('appearance.wordmark', $data['wordmark'] ?? '');
        $this->saved = 'Saved. Reload a page to see the change.';
    }

    /** Available themes = "Default (core)" + each child-theme directory under novfora.theme.path. */
    public function themeOptions(): array
    {
        $opts = [['value' => '', 'label' => 'Default (core)']];
        $path = (string) config('novfora.theme.path');
        if (is_dir($path)) {
            foreach (glob($path.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                $opts[] = ['value' => $name, 'label' => $name];
            }
        }

        return $opts;
    }

    private function ensureAdmin(): void
    {
        $u = auth()->user();
        abort_unless($u instanceof User && $u->canDo('admin.access', Scope::global()), 403);
        abort_if($u->isStaff() && $u->two_factor_confirmed_at === null, 403);
    }
};
?>

<form wire:submit="save" class="space-y-5">
    @if ($saved)
        <x-ui.alert variant="success">{{ $saved }}</x-ui.alert>
    @endif

    <div id="setting-appearance-active-theme">
        <x-ui.select label="Active theme" name="activeTheme" wire:model="activeTheme"
                     hint="Child themes placed in the themes directory appear here.">
            @foreach ($this->themeOptions() as $opt)
                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div id="setting-appearance-wordmark">
            <x-ui.input label="Wordmark text" name="wordmark" wire:model="wordmark" maxlength="40"
                        hint="Header brand text. Blank = the site name." />
        </div>
        <div id="setting-appearance-accent-color">
            <x-ui.input label="Accent colour" name="accentColor" wire:model="accentColor" placeholder="#245fbb"
                        hint="Hex, e.g. #245fbb. Blank = the built-in Nova Blue. Text colour stays AA-contrast in both modes." />
        </div>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div id="setting-appearance-forum-width">
            <x-ui.select label="Forum width" name="forumWidth" wire:model="forumWidth">
                <option value="boxed-narrow">Boxed — narrow</option>
                <option value="standard">Standard</option>
                <option value="wide">Wide</option>
                <option value="full">Full width</option>
            </x-ui.select>
        </div>
        <div id="setting-appearance-board-list-style">
            <x-ui.select label="Board-list style" name="boardListStyle" wire:model="boardListStyle">
                <option value="info-rich">Info-rich table</option>
                <option value="minimal">Minimal list</option>
            </x-ui.select>
        </div>
        <div id="setting-appearance-poster-position">
            <x-ui.select label="Poster-info position" name="posterPosition" wire:model="posterPosition">
                <option value="left">Left sidebar</option>
                <option value="right">Right sidebar</option>
                <option value="top">Top bar</option>
            </x-ui.select>
        </div>
        <div class="grid grid-cols-2 gap-5">
            <div id="setting-appearance-default-color-mode">
                <x-ui.select label="Default mode" name="defaultColorMode" wire:model="defaultColorMode">
                    <option value="auto">Auto</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </x-ui.select>
            </div>
            <div id="setting-appearance-default-density">
                <x-ui.select label="Default density" name="defaultDensity" wire:model="defaultDensity">
                    <option value="comfortable">Comfortable</option>
                    <option value="compact">Compact</option>
                </x-ui.select>
            </div>
        </div>
    </div>
    <p class="text-xs text-ink-subtle">Default mode &amp; density apply to visitors and signed-out users; members keep their own choice.</p>

    <div>
        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Save changes</span>
            <span wire:loading wire:target="save">Saving…</span>
        </x-ui.button>
    </div>
</form>
