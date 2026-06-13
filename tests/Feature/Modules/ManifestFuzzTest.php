<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Modules\ManifestValidator;
use App\Modules\ModuleException;
use App\Modules\ModuleManifest;

/*
| Property/fuzz test for the UNTRUSTED-INPUT boundary (ADR-0031, apex). The manifest validator must be TOTAL +
| fail-closed: for ANY input — malformed JSON, wrong-typed fields, traversal slugs, reserved namespaces, junk —
| it returns a ModuleManifest (valid) or throws ModuleException (rejected), and NEVER leaks any other Throwable
| (TypeError / Error / uncaught) or fatals. A seeded generator makes the run reproducible.
*/

it('is total + fail-closed on arbitrary manifest input (never a non-ModuleException throwable)', function () {
    $validator = new ManifestValidator;

    // A fixed adversarial corpus (the cases worth pinning explicitly)…
    $corpus = [
        '', ' ', 'null', 'true', 'false', '0', '"x"', '[]', '[1,2,3]', '{}', '{"x":1}',
        '{"slug":123}', '{"slug":"a/b"}', '{"slug":"a/b","version":"x.y"}', '{"slug":"a/b","version":"1.0.0"}',
        '{"slug":"../../etc","version":"1.0.0","api_version":"^1.0"}',
        '{"slug":"UP/PER","version":"1.0.0","api_version":"^1.0"}',
        '{"slug":"a/b","version":"1.0.0","api_version":"^1.0","namespace":"App\\\\Evil\\\\"}',
        '{"slug":"a/b","version":"1.0.0","api_version":"^1.0","namespace":"Mod\\\\A\\\\","provider":"Other\\\\X"}',
        '{"slug":"a/b","version":"1.0.0","api_version":"garbage","permissions":[1,2]}',
        '{"slug":"a/b","version":"1.0.0","api_version":"^1.0","permissions":[{"key":"BAD KEY"}]}',
        '{"slug":"a/b","version":"1.0.0","api_version":"^1.0","requires":{"modules":{"x":["bad"]}}}',
        '{"slug":"a/b","version":"1.0.0","api_version":"^1.0","provides":["routes","made-up"]}',
        str_repeat('{"a":', 200).'1'.str_repeat('}', 200), // deep nesting → JSON depth guard
        '{"slug":"valid/module","name":"Ok","version":"2.3.4","api_version":"^1.0"}', // a happy one
    ];

    // …plus a seeded random pile of objects built from interesting keys + interesting values.
    mt_srand(20260613);
    $keys = ['name', 'slug', 'version', 'api_version', 'namespace', 'provider', 'requires', 'permissions', 'provides', 'description', 'author', 'random'];
    $value = function () use (&$value) {
        return match (mt_rand(0, 8)) {
            0 => mt_rand(-99, 999),
            1 => ['a', 'b', mt_rand(0, 9)],
            2 => true,
            3 => null,
            4 => str_repeat(chr(mt_rand(33, 126)), mt_rand(0, 300)),
            5 => ['key' => mt_rand(0, 1) ? 'x.y' : 999, 'scope_kind' => 'nope'],
            6 => mt_rand(0, 1) ? 'a/b' : '../x',
            7 => ['php' => mt_rand(0, 1) ? '>=8.3' : ['bad']],
            default => 'value-'.mt_rand(0, 9),
        };
    };
    for ($i = 0; $i < 400; $i++) {
        $data = [];
        // (array) coerces array_rand's scalar return (when it picks exactly one) into a list.
        foreach ((array) array_rand(array_flip($keys), mt_rand(1, 6)) as $key) {
            $data[(string) $key] = $value();
        }
        $corpus[] = (string) json_encode($data);
    }

    foreach ($corpus as $json) {
        try {
            $manifest = $validator->fromJson($json);
            // If it accepted, it must be a fully-formed manifest with a path-safe slug.
            expect($manifest)->toBeInstanceOf(ModuleManifest::class)
                ->and($manifest->slug)->toMatch('#^[a-z0-9]+(?:-[a-z0-9]+)*/[a-z0-9]+(?:-[a-z0-9]+)*$#');
        } catch (ModuleException) {
            // The expected fail-closed path — fine. Any OTHER throwable propagates and fails this test.
        }
    }

    expect(true)->toBeTrue();
});
