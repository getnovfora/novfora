<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/*
| Guards the rendered destructive-button + count-badge contract. x-ui.button variant="danger" and the
| notification-bell / inbox-badge count pills render `bg-danger-strong text-white` in BOTH colour modes
| (white is a literal, not a per-mode ink token). So WHITE on --danger-strong must stay AA (>= 4.5:1) in
| every mode. The brand's bright dark value (#e8573f) computed to only 3.58:1 and was darkened to #cf3c28;
| this pins every defined --danger-strong so a future palette retune cannot silently regress the
| destructive button below AA (the in-app AA checker + GroupColorTest cover ink/muted + group colours, not
| this pairing).
*/

it('keeps white text on the danger button (--danger-strong) AA in every colour mode', function () {
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

    $css = (string) file_get_contents(resource_path('css/app.css'));
    preg_match_all('/--danger-strong:\s*(#[0-9a-fA-F]{6})/', $css, $matches);
    $values = array_values(array_unique(array_map('strtolower', $matches[1])));

    // light :root + the two dark blocks → at least 2 distinct values, all defined.
    expect($values)->not->toBeEmpty('--danger-strong is not defined in resources/css/app.css');

    foreach ($values as $hex) {
        expect($ratio('#ffffff', $hex))->toBeGreaterThanOrEqual(4.5, "white text on danger-strong {$hex}");
    }
});
