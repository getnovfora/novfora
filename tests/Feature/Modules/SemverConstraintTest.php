<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Modules\SemverConstraint;

/*
| The module-API compatibility checker (ADR-0031). It backs "know before you enable/upgrade", so a wrong verdict
| would be the difference between a clean refusal and a broken module loading — these pins cover caret (incl.
| 0.x), exact, >=, and wildcard, plus what is / isn't a valid constraint or version.
*/

it('evaluates caret, exact, >= and wildcard constraints', function () {
    expect(SemverConstraint::satisfies('1.0.0', '^1.0'))->toBeTrue()
        ->and(SemverConstraint::satisfies('1.5.2', '^1.0'))->toBeTrue()
        ->and(SemverConstraint::satisfies('2.0.0', '^1.0'))->toBeFalse()   // next major is out
        ->and(SemverConstraint::satisfies('1.0.0', '^1.2'))->toBeFalse()   // below the floor
        ->and(SemverConstraint::satisfies('1.2.0', '^1.2'))->toBeTrue()
        ->and(SemverConstraint::satisfies('0.3.5', '^0.3'))->toBeTrue()    // caret on 0.x locks the minor
        ->and(SemverConstraint::satisfies('0.4.0', '^0.3'))->toBeFalse()
        ->and(SemverConstraint::satisfies('1.0.0', '*'))->toBeTrue()
        ->and(SemverConstraint::satisfies('1.0.0', ''))->toBeTrue()
        ->and(SemverConstraint::satisfies('1.2.3', '>=1.2.0'))->toBeTrue()
        ->and(SemverConstraint::satisfies('1.1.0', '>=1.2.0'))->toBeFalse()
        ->and(SemverConstraint::satisfies('1.2.3', '1.2.3'))->toBeTrue()
        ->and(SemverConstraint::satisfies('1.2.4', '1.2.3'))->toBeFalse();
});

it('recognises valid/invalid constraints and versions', function () {
    expect(SemverConstraint::isValidConstraint('^1.0'))->toBeTrue()
        ->and(SemverConstraint::isValidConstraint('>=2.1.0'))->toBeTrue()
        ->and(SemverConstraint::isValidConstraint('*'))->toBeTrue()
        ->and(SemverConstraint::isValidConstraint('~1.0'))->toBeFalse()    // tilde not supported (rejected loudly)
        ->and(SemverConstraint::isValidConstraint('1.0-beta'))->toBeFalse()
        ->and(SemverConstraint::isValidVersion('1.2.3'))->toBeTrue()
        ->and(SemverConstraint::isValidVersion('1.2'))->toBeTrue()
        ->and(SemverConstraint::isValidVersion('1.x'))->toBeFalse();
});
