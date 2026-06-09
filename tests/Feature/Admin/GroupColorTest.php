<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\GroupManager;
use App\Models\Group;
use App\Support\GroupColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('resolves a name colour from the highest-priority COLOURED group', function () {
    Group::where('slug', 'admins')->update(['color' => 'red']); // priority 100
    Group::where('slug', 'tl1')->update(['color' => 'blue']);   // priority 2

    $user = Users::inGroups(['admins', 'tl1'], ['username' => 'alice']);

    expect($user->displayGroup()?->slug)->toBe('admins')
        ->and($user->nameColor())->toBe('var(--group-red)');
});

it('ignores an uncoloured higher-priority group', function () {
    Group::where('slug', 'admins')->update(['color' => null]); // highest rank, but no colour
    Group::where('slug', 'tl1')->update(['color' => 'blue']);

    $user = Users::inGroups(['admins', 'tl1'], ['username' => 'bob']);

    expect($user->displayGroup()?->slug)->toBe('tl1')
        ->and($user->nameColor())->toBe('var(--group-blue)');
});

it('returns no colour when none of the user groups are coloured', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'carol']);

    expect($user->displayGroup())->toBeNull()
        ->and($user->nameColor())->toBeNull();
});

it('breaks a priority tie by the higher group id (stable)', function () {
    $gm = app(GroupManager::class);
    $first = $gm->create(['name' => 'Alpha', 'color' => 'green', 'priority' => 50]);
    $second = $gm->create(['name' => 'Beta', 'color' => 'violet', 'priority' => 50]);

    $user = Users::inGroups(['members'], ['username' => 'dave']);
    $first->users()->attach($user->id);
    $second->users()->attach($user->id);

    // Same priority → the higher id ($second) wins.
    expect($user->fresh()->displayGroup()?->id)->toBe($second->id)
        ->and($user->fresh()->nameColor())->toBe('var(--group-violet)');
});

it('renders the user-name component with the group colour as an inline token', function () {
    Group::where('slug', 'admins')->update(['color' => 'red']);
    $user = Users::inGroups(['admins'], ['username' => 'erin']);

    $html = Blade::render('<x-ui.user-name :user="$u" />', ['u' => $user->fresh()]);

    expect($html)->toContain('erin')
        ->and($html)->toContain('style="color: var(--group-red);"');
});

it('renders an uncoloured user as plain text (no style, name unchanged)', function () {
    $user = Users::inGroups(['members'], ['username' => 'frank']);

    $html = trim(Blade::render('<x-ui.user-name :user="$u" />', ['u' => $user->fresh()]));

    expect($html)->toBe('frank')->not->toContain('style=');
});

it('renders the fallback for a null user', function () {
    $html = trim(Blade::render('<x-ui.user-name :user="$u" fallback="system" />', ['u' => null]));

    expect($html)->toBe('system');
});

it('keeps every palette colour defined as a CSS token (light + dark)', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    foreach (GroupColor::keys() as $key) {
        expect($css)->toContain("--group-{$key}:");
    }
});

it('keeps every palette colour AA (>= 4.5:1) on every surface it can render on, in BOTH modes', function () {
    $luminance = function (string $hex): float {
        $hex = ltrim($hex, '#');
        $channel = function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
    };
    $ratio = function (string $a, string $b) use ($luminance): float {
        [$la, $lb] = [$luminance($a), $luminance($b)];

        return $la > $lb ? ($la + 0.05) / ($lb + 0.05) : ($lb + 0.05) / ($la + 0.05);
    };

    // The surfaces a coloured name can land on (body / raised / sunken), per mode (app.css).
    $lightSurfaces = ['#f6f8fc', '#ffffff', '#eef1f7'];
    $darkSurfaces = ['#0d111a', '#161c28', '#090c13'];

    foreach (GroupColor::PALETTE as $key => [$label, $light, $dark]) {
        foreach ($lightSurfaces as $surface) {
            expect($ratio($light, $surface))->toBeGreaterThanOrEqual(4.5, "light {$key} on {$surface}");
        }
        foreach ($darkSurfaces as $surface) {
            expect($ratio($dark, $surface))->toBeGreaterThanOrEqual(4.5, "dark {$key} on {$surface}");
        }
    }
});
