<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme;

use App\Models\LayoutWidget;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The layout configurator authority (ADR-0032): the single audited writer of widget placements and the
 * renderer the `<x-region>` outlet calls. Regions are a fixed, named set of outlets templates expose; an admin
 * fills them with widgets (from WidgetRegistry) in an order. Render skips disabled placements and placements
 * whose widget is no longer registered (e.g. a module was disabled), never erroring.
 *
 * SECURITY: a placement's settings are constrained to the chosen widget's DECLARED fields on write (unknown
 * keys dropped), and each widget is responsible for safe output (built-ins escape; the HTML-block widget
 * sanitises). The configurator is admin-gated (admin.access + 2FA) at its ACP surface.
 */
final class LayoutManager
{
    /**
     * The named regions templates expose. Adding one is a MINOR theme-API change (ThemeApi). Keys use '_'
     * (not '.') so they bind cleanly as flat Livewire property keys in the configurator.
     */
    public const REGIONS = [
        'forum_top' => 'Forum index — top',
        'forum_bottom' => 'Forum index — bottom',
    ];

    public function __construct(private readonly WidgetRegistry $widgets) {}

    /** @return array<string,string> region key => label */
    public function regions(): array
    {
        return self::REGIONS;
    }

    public function isRegion(string $region): bool
    {
        return array_key_exists($region, self::REGIONS);
    }

    /** @return Collection<int,LayoutWidget> */
    public function placements(string $region): Collection
    {
        return LayoutWidget::query()->where('region', $region)->orderBy('position')->orderBy('id')->get();
    }

    /** The rendered HTML of a region's enabled, still-registered widgets, in order. '' when empty. */
    public function render(string $region): string
    {
        if (! $this->isRegion($region)) {
            return '';
        }
        $html = '';
        foreach ($this->placements($region) as $placement) {
            if (! $placement->is_enabled) {
                continue;
            }
            $widget = $this->widgets->get($placement->widget_key);
            if ($widget === null) {
                continue;
            }
            $out = $widget->render($placement->settings ?? []);
            if (trim($out) !== '') {
                $html .= '<div class="novfora-region-item">'.$out.'</div>';
            }
        }

        return $html;
    }

    public function add(string $region, string $widgetKey): LayoutWidget
    {
        if (! $this->isRegion($region)) {
            throw new \InvalidArgumentException("Unknown region '{$region}'.");
        }
        if (! $this->widgets->has($widgetKey)) {
            throw new \InvalidArgumentException("Unknown widget '{$widgetKey}'.");
        }
        $position = (int) LayoutWidget::query()->where('region', $region)->max('position') + 1;
        $placement = LayoutWidget::create([
            'region' => $region,
            'widget_key' => $widgetKey,
            'position' => $position,
            'settings' => [],
            'is_enabled' => true,
        ]);
        Audit::log('layout.widget.added', $placement, ['region' => $region, 'widget' => $widgetKey]);

        return $placement;
    }

    /**
     * Update a placement's settings, keeping ONLY the keys the widget declares (unknown keys dropped — a
     * placement can never carry arbitrary settings an attacker smuggled in).
     *
     * @param  array<string,mixed>  $input
     */
    public function updateSettings(LayoutWidget $placement, array $input): void
    {
        $widget = $this->widgets->get($placement->widget_key);
        $allowed = $widget === null ? [] : array_column($widget->fields(), 'key');
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $clean[$key] = $input[$key];
            }
        }
        $placement->update(['settings' => $clean]);
        Audit::log('layout.widget.updated', $placement, ['region' => $placement->region]);
    }

    public function setEnabled(LayoutWidget $placement, bool $enabled): void
    {
        $placement->update(['is_enabled' => $enabled]);
        Audit::log($enabled ? 'layout.widget.enabled' : 'layout.widget.disabled', $placement, ['region' => $placement->region]);
    }

    /** Move a placement up (-1) or down (+1) within its region by swapping positions with its neighbour. */
    public function move(LayoutWidget $placement, int $direction): void
    {
        $neighbour = LayoutWidget::query()
            ->where('region', $placement->region)
            ->when($direction < 0,
                fn ($q) => $q->where('position', '<', $placement->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $placement->position)->orderBy('position'),
            )
            ->first();
        if (! $neighbour instanceof LayoutWidget) {
            return; // already at the edge
        }
        DB::transaction(function () use ($placement, $neighbour): void {
            [$a, $b] = [$placement->position, $neighbour->position];
            $placement->update(['position' => $b]);
            $neighbour->update(['position' => $a]);
        });
    }

    public function remove(LayoutWidget $placement): void
    {
        Audit::log('layout.widget.removed', $placement, ['region' => $placement->region, 'widget' => $placement->widget_key]);
        $placement->delete();
    }
}
