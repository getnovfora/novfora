<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One registered embed consumer (U7, ADR-0103): an external origin allowed to frame / fetch the public
 * embed widgets under its site key. The key is PUBLIC by design (it ships in the consuming page's HTML);
 * its authority is capped at "read guest-visible widget data for this origin" — never a member scope.
 * All writes go through App\Embeds\EmbedManager (validated origin, audited lifecycle); never mass-assign
 * this from a request.
 *
 * @property string $name
 * @property string $origin
 * @property string $key
 * @property bool $is_enabled
 * @property array<int,string>|null $widgets
 */
class EmbedSite extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'bool',
            'widgets' => 'array',
        ];
    }

    /** Widget allowlist check: a null list means every built-in widget. */
    public function allowsWidget(string $widget): bool
    {
        return $this->widgets === null || in_array($widget, $this->widgets, true);
    }
}
