<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Text;

/**
 * A bounded, line-based three-way merge (U11 / ADR-0112) for sandbox-template sources: given the BASE a
 * template edit was made from, the admin's edited OURS, and the new shipped THEIRS, produce the merged
 * source when the edits don't overlap — or report a conflict.
 *
 * Design constraints (these are the security posture, not style):
 *  - **A conflict returns merged = null.** Conflict markers are NEVER produced, so a caller can never store
 *    or render them by accident; conflict resolution is an explicit human act in the ACP.
 *  - **Strictly bounded.** Inputs over MAX_BYTES/MAX_LINES, or diffs beyond MAX_EDIT_DISTANCE, refuse to
 *    merge (reported as a conflict) instead of doing unbounded work — a hostile/pathological input can cost
 *    at most O(MAX_LINES · MAX_EDIT_DISTANCE) time and memory.
 *  - **Pure.** No IO, no state; the caller (TemplateSync) re-lints + re-parses any merged output through the
 *    sandbox before it may be stored — two individually-safe inputs can interleave into a forbidden token.
 *
 * Clean-room implementation: a Myers greedy shortest-edit-script diff (base↔ours, base↔theirs) yielding
 * matched-line maps, then the classic diff3 chunk walk over base lines stable in BOTH derivatives.
 */
final class Diff3
{
    public const MAX_BYTES = 65536;

    public const MAX_LINES = 2000;

    /** Myers D cap per side-diff — beyond this the sources are too divergent to auto-merge honestly. Kept
     *  modest so the O(D^2) backtrack trace stays small (~D^2 int cells; 400 → ~160k, a few MB) on the
     *  no-SSH shared-host tier where an OOM would be a fatal, uncatchable E_ERROR (apex finding D1). */
    public const MAX_EDIT_DISTANCE = 400;

    /**
     * @return array{clean: bool, merged: ?string, conflicts: int}
     */
    public static function merge(string $base, string $ours, string $theirs): array
    {
        // Fast paths — no diff needed.
        if ($ours === $theirs) {
            return ['clean' => true, 'merged' => $ours, 'conflicts' => 0];
        }
        if ($base === $ours) {
            return ['clean' => true, 'merged' => $theirs, 'conflicts' => 0];
        }
        if ($base === $theirs) {
            return ['clean' => true, 'merged' => $ours, 'conflicts' => 0];
        }

        foreach ([$base, $ours, $theirs] as $side) {
            if (strlen($side) > self::MAX_BYTES || substr_count($side, "\n") + 1 > self::MAX_LINES) {
                return ['clean' => false, 'merged' => null, 'conflicts' => 1];
            }
        }

        $b = explode("\n", $base);
        $o = explode("\n", $ours);
        $t = explode("\n", $theirs);

        $matchO = self::matches($b, $o);
        $matchT = self::matches($b, $t);
        if ($matchO === null || $matchT === null) {
            return ['clean' => false, 'merged' => null, 'conflicts' => 1]; // too divergent — refuse, don't grind
        }

        // Walk base indices; a base line "stable" in BOTH sides anchors the merge. Between anchors, the
        // three chunks decide: only-ours-changed → ours; only-theirs-changed → theirs; both same → either;
        // both different → conflict.
        $out = [];
        $conflicts = 0;
        $bi = 0;
        $oi = 0;
        $ti = 0;
        $bn = count($b);

        while (true) {
            // Advance through the run of anchored lines.
            while ($bi < $bn && isset($matchO[$bi], $matchT[$bi]) && $matchO[$bi] === $oi && $matchT[$bi] === $ti) {
                $out[] = $b[$bi];
                $bi++;
                $oi++;
                $ti++;
            }

            if ($bi >= $bn && $oi >= count($o) && $ti >= count($t)) {
                break; // all three exhausted
            }

            // Find the next base index anchored in both sides (or the end).
            $nextB = $bi;
            while ($nextB < $bn && ! (isset($matchO[$nextB], $matchT[$nextB]))) {
                $nextB++;
            }
            $nextO = $nextB < $bn ? $matchO[$nextB] : count($o);
            $nextT = $nextB < $bn ? $matchT[$nextB] : count($t);

            $chunkB = array_slice($b, $bi, $nextB - $bi);
            $chunkO = array_slice($o, $oi, $nextO - $oi);
            $chunkT = array_slice($t, $ti, $nextT - $ti);

            if ($chunkO === $chunkB) {
                // Ours left this region alone → take theirs.
                foreach ($chunkT as $line) {
                    $out[] = $line;
                }
            } elseif ($chunkT === $chunkB) {
                // Theirs left this region alone → take ours.
                foreach ($chunkO as $line) {
                    $out[] = $line;
                }
            } elseif ($chunkO === $chunkT && $chunkB !== []) {
                // Both made the SAME modification to a real base region → take it once. Requiring a
                // NON-EMPTY base chunk is the fix for the duplicate-line collapse (apex finding D2):
                // when chunkB is empty, an equal chunkO/chunkT is a COINCIDENTAL duplicate match from
                // two independent insertions near a repeated line, not a shared edit — collapsing it
                // would silently drop one side's line. Fall through to conflict instead.
                foreach ($chunkO as $line) {
                    $out[] = $line;
                }
            } else {
                $conflicts++;

                return ['clean' => false, 'merged' => null, 'conflicts' => $conflicts];
            }

            $bi = $nextB;
            $oi = $nextO;
            $ti = $nextT;

            if ($bi >= $bn && $oi >= count($o) && $ti >= count($t)) {
                break;
            }
            if ($bi >= $bn && ($oi < count($o) || $ti < count($t))) {
                // Trailing material with no further anchors was consumed as one chunk above; if both sides
                // still hold unanchored tails here they diverged at the very end.
                if ($oi < count($o) && $ti < count($t)) {
                    return ['clean' => false, 'merged' => null, 'conflicts' => $conflicts + 1];
                }
                for (; $oi < count($o); $oi++) {
                    $out[] = $o[$oi];
                }
                for (; $ti < count($t); $ti++) {
                    $out[] = $t[$ti];
                }
                break;
            }
        }

        return ['clean' => true, 'merged' => implode("\n", $out), 'conflicts' => 0];
    }

    /**
     * Myers greedy LCS: map of base-line-index → side-line-index for every line the two sequences share in
     * order (the "snakes"). Returns null when the edit distance exceeds MAX_EDIT_DISTANCE.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array<int,int>|null
     */
    private static function matches(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);

        // The minimum edit distance is at least |n - m|; if that already exceeds the cap, the endpoint can
        // never be reached within MAX_EDIT_DISTANCE, so bail BEFORE building the O(D^2) trace (a tiny base
        // vs a large override is the ordinary conflict path — apex finding D1: don't grind, don't OOM).
        if (abs($n - $m) > self::MAX_EDIT_DISTANCE) {
            return null;
        }

        $max = min($n + $m, self::MAX_EDIT_DISTANCE);

        // V[k] = furthest x on diagonal k; store a copy per D for backtracking.
        $v = [1 => 0];
        $trace = [];

        $found = false;
        for ($d = 0; $d <= $max; $d++) {
            $trace[$d] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0))) {
                    $x = $v[$k + 1] ?? 0;      // down: insertion in b
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1; // right: deletion from a
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $found = true;
                    break 2;
                }
            }
        }

        if (! $found) {
            return null;
        }

        // Backtrack through the traced V arrays, recording each snake's matched lines.
        $matches = [];
        $x = $n;
        $y = $m;
        for ($d = count($trace) - 1; $d >= 0 && ($x > 0 || $y > 0); $d--) {
            $vd = $trace[$d];
            $k = $x - $y;

            if ($k === -$d || ($k !== $d && ($vd[$k - 1] ?? 0) < ($vd[$k + 1] ?? 0))) {
                $prevK = $k + 1; // came from an insertion (down)
            } else {
                $prevK = $k - 1; // came from a deletion (right)
            }

            $prevX = $vd[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            // The snake: diagonal run back from (x,y) to the move landing point.
            $moveX = $d > 0 ? ($prevK === $k + 1 ? $prevX : $prevX + 1) : 0;
            $moveY = $moveX - $k;
            while ($x > $moveX && $y > $moveY) {
                $x--;
                $y--;
                $matches[$x] = $y;
            }

            if ($d > 0) {
                $x = $prevX;
                $y = $prevY;
            }
        }

        return $matches;
    }
}
