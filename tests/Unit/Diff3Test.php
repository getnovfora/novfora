<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Text\Diff3;

/*
| U11 (ADR-0112) — the bounded three-way merge. The invariants the sync path depends on:
| a conflict NEVER yields merged output (no markers can ever be stored), clean merges preserve
| both sides' disjoint edits, and pathological inputs are refused, not ground through.
*/

it('takes theirs when ours is unchanged, and ours when theirs is unchanged', function () {
    $base = "a\nb\nc";
    expect(Diff3::merge($base, $base, "a\nB\nc"))->toBe(['clean' => true, 'merged' => "a\nB\nc", 'conflicts' => 0]);
    expect(Diff3::merge($base, "a\nB\nc", $base))->toBe(['clean' => true, 'merged' => "a\nB\nc", 'conflicts' => 0]);
    expect(Diff3::merge($base, "x\ny", "x\ny"))->toBe(['clean' => true, 'merged' => "x\ny", 'conflicts' => 0]);
});

it('merges disjoint line edits from both sides', function () {
    $base = "one\ntwo\nthree\nfour\nfive";
    $ours = "ONE\ntwo\nthree\nfour\nfive";     // edits line 1
    $theirs = "one\ntwo\nthree\nfour\nFIVE";   // edits line 5

    $r = Diff3::merge($base, $ours, $theirs);
    expect($r['clean'])->toBeTrue()
        ->and($r['merged'])->toBe("ONE\ntwo\nthree\nfour\nFIVE");
});

it('merges a disjoint insertion and deletion', function () {
    $base = "alpha\nbravo\ncharlie\ndelta\necho";
    $ours = "alpha\nnew-line\nbravo\ncharlie\ndelta\necho"; // inserts after alpha
    $theirs = "alpha\nbravo\ncharlie\necho";                // deletes delta

    $r = Diff3::merge($base, $ours, $theirs);
    expect($r['clean'])->toBeTrue()
        ->and($r['merged'])->toBe("alpha\nnew-line\nbravo\ncharlie\necho");
});

it('reports a conflict (merged = null, never markers) when both edit the same line differently', function () {
    $base = "one\ntwo\nthree";
    $r = Diff3::merge($base, "one\nTWO-ours\nthree", "one\nTWO-theirs\nthree");

    expect($r['clean'])->toBeFalse()
        ->and($r['merged'])->toBeNull()
        ->and($r['conflicts'])->toBeGreaterThan(0);
});

it('is clean when both sides make the identical change', function () {
    $base = "one\ntwo\nthree";
    $r = Diff3::merge($base, "one\nTWO\nthree", "one\nTWO\nthree");
    expect($r)->toBe(['clean' => true, 'merged' => "one\nTWO\nthree", 'conflicts' => 0]);
});

it('conflicts when both sides append different tails', function () {
    $base = "a\nb";
    $r = Diff3::merge($base, "a\nb\nours-tail", "a\nb\ntheirs-tail");
    expect($r['clean'])->toBeFalse()->and($r['merged'])->toBeNull();
});

it('merges cleanly when only one side appends a tail', function () {
    $base = "a\nb";
    $r = Diff3::merge($base, "a\nb\nours-tail", "a\nb");
    expect($r)->toBe(['clean' => true, 'merged' => "a\nb\nours-tail", 'conflicts' => 0]);
});

it('refuses oversized input as a conflict instead of unbounded work', function () {
    $big = str_repeat("line\n", 3000);
    $r = Diff3::merge($big, $big.'x', 'y'.$big);
    expect($r['clean'])->toBeFalse()->and($r['merged'])->toBeNull();
});

it('conflicts (never silently collapses) on the duplicate-line ambiguity — apex D2', function () {
    // base two identical lines; ours appends a third; theirs inserts a different line into the run.
    // A naive collapse would drop one copy — the safe answer is a conflict, not a wrong clean merge.
    $r = Diff3::merge("A\nA", "A\nA\nA", "A\nB\nA\nA");
    expect($r['clean'])->toBeFalse()
        ->and($r['merged'])->toBeNull();

    // Isomorphic real-template shape: repeated structural lines.
    $r2 = Diff3::merge("<hr>\n<hr>", "<hr>\n<hr>\n<hr>", "<hr>\n<span>or</span>\n<hr>\n<hr>");
    expect($r2['clean'])->toBeFalse()->and($r2['merged'])->toBeNull();
});

it('bails to conflict on a tiny-base vs huge-override diff without building the trace — apex D1', function () {
    // |n - m| far exceeds MAX_EDIT_DISTANCE, so matches() must refuse before the O(D^2) trace — the
    // ordinary "admin grew a 6-line default into a 1500-line override, then the default changed" path.
    $base = implode("\n", array_fill(0, 6, 'default line'));
    $ours = implode("\n", array_map(fn ($i) => "custom line {$i}", range(1, 1500)));
    $theirs = implode("\n", array_fill(0, 6, 'changed default line'));

    $r = Diff3::merge($base, $ours, $theirs);
    expect($r['clean'])->toBeFalse()->and($r['merged'])->toBeNull();
});

it('fuzz: disjoint edits always merge cleanly with both sides preserved and no markers', function () {
    mt_srand(20260717);

    for ($round = 0; $round < 60; $round++) {
        // Unique-content base lines (ambiguous duplicate lines are legitimately conflict-prone).
        $n = mt_rand(20, 40);
        $base = [];
        for ($i = 0; $i < $n; $i++) {
            $base[] = "line-{$i}-".mt_rand(1000, 9999);
        }

        // Ours edits only in the first third; theirs only in the last third — structurally disjoint.
        $ours = $base;
        $theirs = $base;
        $ourEdit = 'ours-edit-'.$round;
        $theirEdit = 'theirs-edit-'.$round;
        $ours[mt_rand(0, intdiv($n, 3) - 1)] = $ourEdit;
        $theirs[mt_rand(intdiv(2 * $n, 3), $n - 1)] = $theirEdit;
        if (mt_rand(0, 1) === 1) {
            array_splice($ours, mt_rand(0, intdiv($n, 3)), 0, ["ours-insert-{$round}"]);
        }
        if (mt_rand(0, 1) === 1) {
            array_splice($theirs, mt_rand(intdiv(2 * $n, 3), $n), 0, ["theirs-insert-{$round}"]);
        }

        $r = Diff3::merge(implode("\n", $base), implode("\n", $ours), implode("\n", $theirs));

        expect($r['clean'])->toBeTrue()
            ->and($r['merged'])->toContain($ourEdit)
            ->and($r['merged'])->toContain($theirEdit)
            ->and($r['merged'])->not->toContain('<<<<<<<');
    }
});

it('fuzz: identical mutations on both sides always merge to exactly that mutation', function () {
    mt_srand(99);

    for ($round = 0; $round < 30; $round++) {
        $n = mt_rand(5, 30);
        $base = [];
        for ($i = 0; $i < $n; $i++) {
            $base[] = "b{$i}-".mt_rand(100, 999);
        }
        $mut = $base;
        $mut[mt_rand(0, $n - 1)] = "mut-{$round}";
        if (mt_rand(0, 1) === 1) {
            array_splice($mut, mt_rand(0, $n), 0, ["ins-{$round}"]);
        }
        if (count($mut) > 2 && mt_rand(0, 1) === 1) {
            array_splice($mut, mt_rand(0, count($mut) - 1), 1);
        }

        $r = Diff3::merge(implode("\n", $base), implode("\n", $mut), implode("\n", $mut));
        expect($r)->toBe(['clean' => true, 'merged' => implode("\n", $mut), 'conflicts' => 0]);
    }
});
