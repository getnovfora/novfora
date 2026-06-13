<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

/**
 * A small, deliberately-narrow semver constraint checker for the module/plugin contract (ADR-0031). It backs
 * the "know before you upgrade" compatibility check (a module's `api_version` against the core MODULE API
 * version) and inter-module `requires` checks — without pulling in composer/semver.
 *
 * SUPPORTED forms (anything else is rejected at manifest-validation time with a clear message, so an author
 * never gets a silently-wrong "compatible" verdict):
 *   - `*` or `` (empty)        → any version
 *   - `1.2.3` / `1.2` / `1`    → exact (a 2-part / 1-part value zero-fills: `1.2` == `1.2.0`)
 *   - `^1.2.3`                 → caret: >= the floor, < the next LEFT-MOST-non-zero bump (npm/composer caret)
 *   - `>=1.2.3`                → at least
 *
 * Versions are plain `x.y.z` (zero-filled). Pre-release / build metadata is out of scope for the module API
 * contract and is rejected, keeping the surface auditable.
 */
final class SemverConstraint
{
    /** Whether $version satisfies $constraint. Both are validated by the manifest layer before they reach here. */
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        $v = self::parse($version);
        if ($v === null) {
            return false;
        }

        if (str_starts_with($constraint, '^')) {
            return self::satisfiesCaret($v, substr($constraint, 1));
        }
        if (str_starts_with($constraint, '>=')) {
            $floor = self::parse(trim(substr($constraint, 2)));

            return $floor !== null && self::compare($v, $floor) >= 0;
        }

        // Bare value → exact match (after zero-filling both sides).
        $exact = self::parse($constraint);

        return $exact !== null && self::compare($v, $exact) === 0;
    }

    /** Whether a string is a constraint this checker understands (used by the manifest validator). */
    public static function isValidConstraint(string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        $body = $constraint;
        foreach (['^', '>='] as $op) {
            if (str_starts_with($constraint, $op)) {
                $body = trim(substr($constraint, strlen($op)));
                break;
            }
        }

        return self::parse($body) !== null;
    }

    /** Whether a string is a plain `x[.y[.z]]` version (the only version shape the contract accepts). */
    public static function isValidVersion(string $version): bool
    {
        return self::parse($version) !== null;
    }

    /** @param array{int,int,int} $v */
    private static function satisfiesCaret(array $v, string $floorRaw): bool
    {
        $floor = self::parse($floorRaw);
        if ($floor === null) {
            return false;
        }
        if (self::compare($v, $floor) < 0) {
            return false;
        }

        // Caret upper bound: bump the left-most NON-ZERO component, zero the rest (npm/composer semantics).
        [$fMajor, $fMinor, $fPatch] = $floor;
        if ($fMajor > 0) {
            $ceil = [$fMajor + 1, 0, 0];
        } elseif ($fMinor > 0) {
            $ceil = [0, $fMinor + 1, 0];
        } else {
            $ceil = [0, 0, $fPatch + 1];
        }

        return self::compare($v, $ceil) < 0;
    }

    /**
     * Parse `x[.y[.z]]` (digits only) into a zero-filled triple, or null if it is not that shape.
     *
     * @return array{int,int,int}|null
     */
    private static function parse(string $version): ?array
    {
        $version = trim($version);
        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $version, $m)) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }

    /**
     * @param  array{int,int,int}  $a
     * @param  array{int,int,int}  $b
     * @return int -1 | 0 | 1
     */
    private static function compare(array $a, array $b): int
    {
        return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
    }
}
