<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A3: add a `min_reputation` bar to the trust auto-promotion criteria (tl2/tl3). On a FRESH install GroupSeeder
// seeds these values into the tl2/tl3 rows directly; on an UPGRADE the seeder is NOT re-run (RH-10/ADR-0021:
// auto-upgrade runs migrations only), so this migration backfills the key onto the existing rows. It is
// idempotent and non-destructive: it only sets `min_reputation` when the key is ABSENT (never clobbering an
// operator-tuned value), and at fresh-install migrate time the rows don't exist yet so it is a clean no-op.
return new class extends Migration
{
    /** @var array<string,int> */
    private array $thresholds = ['tl2' => 10, 'tl3' => 50];

    public function up(): void
    {
        foreach ($this->thresholds as $slug => $minReputation) {
            $rules = $this->rulesFor($slug);
            if ($rules === null || array_key_exists('min_reputation', $rules)) {
                continue;
            }
            $rules['min_reputation'] = $minReputation;
            DB::table('groups')->where('slug', $slug)->update(['auto_promotion' => json_encode($rules)]);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->thresholds) as $slug) {
            $rules = $this->rulesFor($slug);
            if ($rules === null || ! array_key_exists('min_reputation', $rules)) {
                continue;
            }
            unset($rules['min_reputation']);
            DB::table('groups')->where('slug', $slug)->update(['auto_promotion' => json_encode($rules)]);
        }
    }

    /** @return array<string,mixed>|null the decoded auto_promotion JSON, or null when the group row is absent */
    private function rulesFor(string $slug): ?array
    {
        $row = DB::table('groups')->where('slug', $slug)->first();
        if ($row === null) {
            return null;
        }
        $rules = json_decode((string) ($row->auto_promotion ?? ''), true);

        return is_array($rules) ? $rules : [];
    }
};
