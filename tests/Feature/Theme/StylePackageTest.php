<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\SiteTheme;
use App\Modules\Packaging\PackageException;
use App\Settings\Settings;
use App\Theme\Packaging\StylePackage;
use App\Theme\StyleThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| U12 (NOV-110) — style import/export. A theme round-trips through a portable zip whose manifest IS the
| registry theme format; import rides ArchiveGuard safe extraction + StyleThemeManager's strict validation,
| never a second code path. Plus the site-wide global custom-CSS box.
*/

uses(RefreshDatabase::class);

it('round-trips a theme through export → import with fields intact', function () {
    Storage::fake('public');
    $m = app(StyleThemeManager::class);
    $theme = $m->create([
        'name' => 'Ocean',
        'accent_color' => '#2563eb',
        'tokens' => ['surface' => '#ffffff', 'ink' => '#101010'],
        'custom_css' => '.x{color:red}',
    ]);

    $zip = app(StylePackage::class)->export($theme);
    expect(is_file($zip))->toBeTrue();

    // The manifest is the registry theme format.
    $za = new ZipArchive;
    $za->open($zip);
    $manifest = json_decode($za->getFromName('style.json'), true);
    $za->close();
    expect($manifest['format'])->toBe('novfora-style')
        ->and($manifest['tokens'])->toBe(['surface' => '#ffffff', 'ink' => '#101010'])
        ->and($manifest)->toHaveKey('tokens_dark'); // format slot reserved for the U10 dark layer

    $imported = app(StylePackage::class)->import($zip);
    @unlink($zip);

    expect($imported->id)->not->toBe($theme->id)          // a NEW theme, never overwrites
        ->and($imported->accent_color)->toBe('#2563eb')
        ->and($imported->tokens)->toBe(['surface' => '#ffffff', 'ink' => '#101010'])
        ->and($imported->custom_css)->toContain('color:red')
        ->and($imported->is_active)->toBeFalse();          // import never activates
});

it('exports and re-imports a bound image asset', function () {
    Storage::fake('public');
    $m = app(StyleThemeManager::class);
    $theme = $m->create(['name' => 'Withlogo']);
    $m->storeAsset($theme, 'logo', UploadedFile::fake()->image('logo.png', 32, 32));

    $zip = app(StylePackage::class)->export($theme->refresh());
    $imported = app(StylePackage::class)->import($zip);
    @unlink($zip);

    expect($imported->logo_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($imported->logo_path))->toBeTrue();
});

it('rejects a non-style zip and a malformed manifest', function () {
    Storage::fake('public');

    $notStyle = tempnam(sys_get_temp_dir(), 'z');
    $za = new ZipArchive;
    $za->open($notStyle, ZipArchive::OVERWRITE);
    $za->addFromString('readme.txt', 'hello');
    $za->close();
    expect(fn () => app(StylePackage::class)->import($notStyle))->toThrow(PackageException::class);
    @unlink($notStyle);

    $badManifest = tempnam(sys_get_temp_dir(), 'z');
    $za = new ZipArchive;
    $za->open($badManifest, ZipArchive::OVERWRITE);
    $za->addFromString('style.json', '{not json');
    $za->close();
    expect(fn () => app(StylePackage::class)->import($badManifest))->toThrow(PackageException::class);
    @unlink($badManifest);
});

it('runs an imported manifest through the strict token validator (no CSS injection)', function () {
    Storage::fake('public');
    $evil = tempnam(sys_get_temp_dir(), 'z');
    $za = new ZipArchive;
    $za->open($evil, ZipArchive::OVERWRITE);
    $za->addFromString('style.json', json_encode([
        'format' => 'novfora-style', 'format_version' => 1, 'name' => 'Evil',
        'tokens' => ['surface' => 'red;} body{display:none', 'ink' => '#111111'],
        'accent_color' => 'nonsense',
    ]));
    $za->close();

    $theme = app(StylePackage::class)->import($evil);
    @unlink($evil);

    // The injection value is dropped; only the valid token survives; the bad accent → null (inherit).
    expect($theme->tokens)->toBe(['ink' => '#111111'])
        ->and($theme->accent_color)->toBeNull();
});

it('ignores a manifest asset path that escapes the staging root (traversal)', function () {
    Storage::fake('public');
    $trav = tempnam(sys_get_temp_dir(), 'z');
    $za = new ZipArchive;
    $za->open($trav, ZipArchive::OVERWRITE);
    $za->addFromString('style.json', json_encode([
        'format' => 'novfora-style', 'format_version' => 1, 'name' => 'Trav',
        'assets' => ['logo' => '../../../../etc/passwd'],
    ]));
    $za->close();

    $theme = app(StylePackage::class)->import($trav);
    @unlink($trav);
    expect($theme->logo_path)->toBeNull(); // the traversal path is refused, not bound
});

it('emits and sanitises the site-wide global custom CSS on the page', function () {
    $this->seed();
    app(Settings::class)->set('appearance.global_custom_css', 'body{background:#abcdef}</style><script>alert(1)</script>');

    $html = $this->get(route('forums.index'))->assertOk()->getContent();
    // The CSS is applied, and the </style> breakout is stripped so a following <script> can never escape
    // the style element (a <script> left INSIDE <style> is inert text, not executed).
    expect($html)->toContain('body{background:#abcdef}')
        ->and($html)->not->toContain('</style><script>alert(1)');
});

it('lets a 2FA admin save the global custom CSS box (sanitised)', function () {
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    Livewire::test('admin.settings.appearance')
        ->set('globalCustomCss', ':root{--radius-md:2px}</style>')
        ->call('save')
        ->assertHasNoErrors();

    // </style> stripped on store so nothing can break out of the emitted <style> element.
    expect(app(Settings::class)->string('appearance.global_custom_css'))
        ->toContain(':root{--radius-md:2px}')
        ->and(app(Settings::class)->string('appearance.global_custom_css'))->not->toContain('</style');
});

it('lets a 2FA admin export and import a theme through the ACP', function () {
    Storage::fake('public');
    $this->seed();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));
    $theme = app(StyleThemeManager::class)->create(['name' => 'ACP Export', 'tokens' => ['surface' => '#fefefe']]);

    // Export returns a streamed zip download.
    $response = Livewire::test('admin.settings.themes')->call('exportTheme', $theme->id);
    $response->assertFileDownloaded();

    // Import a package built from that theme (Livewire needs a testing upload carrying the real bytes).
    $zip = app(StylePackage::class)->export($theme);
    $upload = UploadedFile::fake()->createWithContent('style.zip', (string) file_get_contents($zip));
    @unlink($zip);
    Livewire::test('admin.settings.themes')
        ->set('importUpload', $upload)
        ->call('importStyle')
        ->assertHasNoErrors();

    expect(SiteTheme::where('name', 'ACP Export')->count())->toBe(2); // original + imported copy
});
