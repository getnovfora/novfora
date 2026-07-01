<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Navigation;

use App\Community\MembersDirectory;
use App\Groups\GroupDirectory;
use App\Models\NavigationItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Public navigation authority. The app layout asks this service for a surface-specific tree; the ACP writes
 * through it so ordering, sanitisation, visibility rules, and audit logging stay in one place.
 */
final class NavigationManager
{
    public const SURFACE_DESKTOP = 'desktop';

    public const SURFACE_MOBILE = 'mobile';

    public const LINK_ROUTE = 'route';

    public const LINK_URL = 'url';

    public const VISIBILITY_EVERYONE = 'everyone';

    public const VISIBILITY_AUTHENTICATED = 'authenticated';

    public const VISIBILITY_GUESTS = 'guests';

    public const VISIBILITY_GROUPS = 'groups';

    public const VISIBILITY_PUBLIC_GROUPS_DIRECTORY = 'public_groups_directory';

    public const VISIBILITY_MEMBERS_DIRECTORY = 'members_directory';

    /** @var array<string,string> route name => admin label */
    public const ROUTE_OPTIONS = [
        'forums.index' => 'Forums index',
        'clubs.index' => 'Clubs directory',
        'trending.index' => 'Trending',
        'groups.index' => 'Groups directory',
        'members.index' => 'Members directory',
        'members.top' => 'Top members',
        'tags.index' => 'Tags',
        'whats-new' => "What's new",
    ];

    /** @var array<string,string> visibility key => admin label */
    public const VISIBILITY_OPTIONS = [
        self::VISIBILITY_EVERYONE => 'Everyone',
        self::VISIBILITY_AUTHENTICATED => 'Signed-in members',
        self::VISIBILITY_GUESTS => 'Guests only',
        self::VISIBILITY_GROUPS => 'Selected groups',
        self::VISIBILITY_PUBLIC_GROUPS_DIRECTORY => 'When public groups exist',
        self::VISIBILITY_MEMBERS_DIRECTORY => 'When the members directory is visible',
    ];

    /** @var list<string> icon names from resources/views/components/ui/icon.blade.php */
    public const ICON_OPTIONS = [
        '', 'home', 'folder', 'users', 'grid', 'chart', 'globe', 'bell', 'message', 'pin', 'search',
        'shield', 'flag', 'list', 'external',
    ];

    /** @var list<array<string,mixed>> */
    private const DEFAULT_ITEMS = [
        ['title' => 'Forums', 'route_name' => 'forums.index', 'position' => 1, 'visibility' => self::VISIBILITY_EVERYONE],
        ['title' => 'Clubs', 'route_name' => 'clubs.index', 'position' => 2, 'visibility' => self::VISIBILITY_EVERYONE],
        ['title' => 'Trending', 'route_name' => 'trending.index', 'position' => 3, 'show_on_mobile' => false, 'visibility' => self::VISIBILITY_EVERYONE],
        ['title' => 'Groups', 'route_name' => 'groups.index', 'position' => 4, 'visibility' => self::VISIBILITY_PUBLIC_GROUPS_DIRECTORY],
        ['title' => 'Members', 'route_name' => 'members.index', 'position' => 5, 'visibility' => self::VISIBILITY_MEMBERS_DIRECTORY],
        ['title' => "What's new", 'route_name' => 'whats-new', 'position' => 6, 'visibility' => self::VISIBILITY_AUTHENTICATED],
    ];

    /**
     * Public render tree for the requested surface.
     *
     * @return list<array{title:string,url:?string,icon:?string,opens_new_tab:bool,children:list<array{title:string,url:?string,icon:?string,opens_new_tab:bool,children:list<array{}>}>}>
     */
    public function tree(?User $user, string $surface): array
    {
        $surface = $surface === self::SURFACE_MOBILE ? self::SURFACE_MOBILE : self::SURFACE_DESKTOP;

        try {
            $items = NavigationItem::query()
                ->where('is_enabled', true)
                ->where($surface === self::SURFACE_MOBILE ? 'show_on_mobile' : 'show_on_desktop', true)
                ->orderBy('parent_id')
                ->orderBy('position')
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return $this->defaultTree($user, $surface);
        }

        /** @var array<int,list<NavigationItem>> $byParent */
        $byParent = [];
        foreach ($items as $item) {
            if (! $this->visibleTo($item, $user)) {
                continue;
            }
            $byParent[(int) ($item->parent_id ?? 0)][] = $item;
        }

        $tree = [];
        foreach ($byParent[0] ?? [] as $item) {
            $children = [];
            foreach ($byParent[(int) $item->id] ?? [] as $child) {
                $childNode = $this->nodeFromItem($child, []);
                if ($childNode['url'] !== null) {
                    $children[] = $childNode;
                }
            }

            $node = $this->nodeFromItem($item, $children);
            if ($node['url'] !== null || $children !== []) {
                $tree[] = $node;
            }
        }

        return $tree;
    }

    /** @return EloquentCollection<int, NavigationItem> */
    public function items(): EloquentCollection
    {
        return NavigationItem::query()
            ->with('parent')
            ->orderBy('parent_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function create(array $input): NavigationItem
    {
        $data = $this->normalise($input);
        $data['position'] = $this->nextPosition($data['parent_id'] ?? null);

        $item = NavigationItem::create($data);
        Audit::log('navigation.item.created', $item, ['title' => $item->title]);

        return $item;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function update(NavigationItem $item, array $input): void
    {
        $data = $this->normalise($input, $item);
        if (($data['parent_id'] ?? null) !== $item->parent_id) {
            $data['position'] = $this->nextPosition($data['parent_id'] ?? null);
        }

        $item->update($data);
        Audit::log('navigation.item.updated', $item, ['title' => $item->title]);
    }

    public function setEnabled(NavigationItem $item, bool $enabled): void
    {
        $item->update(['is_enabled' => $enabled]);
        Audit::log($enabled ? 'navigation.item.enabled' : 'navigation.item.disabled', $item, ['title' => $item->title]);
    }

    /** Move an item up (-1) or down (+1) among siblings by swapping positions with its neighbour. */
    public function move(NavigationItem $item, int $direction): void
    {
        $neighbour = NavigationItem::query()
            ->where('parent_id', $item->parent_id)
            ->when($direction < 0,
                fn ($q) => $q->where('position', '<', $item->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $item->position)->orderBy('position'),
            )
            ->first();

        if (! $neighbour instanceof NavigationItem) {
            return;
        }

        DB::transaction(function () use ($item, $neighbour): void {
            [$a, $b] = [$item->position, $neighbour->position];
            $item->update(['position' => $b]);
            $neighbour->update(['position' => $a]);
        });
    }

    public function remove(NavigationItem $item): void
    {
        Audit::log('navigation.item.removed', $item, ['title' => $item->title]);
        $item->delete();
    }

    /**
     * @param  list<array<string,mixed>>  $children
     * @return array{title:string,url:?string,icon:?string,opens_new_tab:bool,children:list<array<string,mixed>>}
     */
    private function nodeFromItem(NavigationItem $item, array $children): array
    {
        return [
            'title' => $item->title,
            'url' => $this->urlFor($item->link_type, $item->route_name, $item->url),
            'icon' => $item->icon !== '' ? $item->icon : null,
            'opens_new_tab' => $item->opens_new_tab,
            'children' => $children,
        ];
    }

    private function visibleTo(NavigationItem $item, ?User $user): bool
    {
        return match ($item->visibility) {
            self::VISIBILITY_AUTHENTICATED => $user instanceof User,
            self::VISIBILITY_GUESTS => ! $user instanceof User,
            self::VISIBILITY_GROUPS => $this->userHasAnyGroup($user, $item->group_ids ?? []),
            self::VISIBILITY_PUBLIC_GROUPS_DIRECTORY => GroupDirectory::isEnabled(),
            self::VISIBILITY_MEMBERS_DIRECTORY => MembersDirectory::visibleTo($user),
            default => true,
        };
    }

    /**
     * @param  array<int,mixed>  $groupIds
     */
    private function userHasAnyGroup(?User $user, array $groupIds): bool
    {
        if (! $user instanceof User || $groupIds === []) {
            return false;
        }

        $allowed = array_map('intval', $groupIds);

        return $user->groups()->whereIn('groups.id', $allowed)->exists();
    }

    private function urlFor(string $linkType, ?string $routeName, ?string $url): ?string
    {
        if ($linkType === self::LINK_ROUTE) {
            $name = (string) $routeName;

            return array_key_exists($name, self::ROUTE_OPTIONS) && Route::has($name) ? route($name) : null;
        }

        return $this->safeUrl((string) $url);
    }

    /**
     * @return list<array{title:string,url:?string,icon:?string,opens_new_tab:bool,children:list<array{}>}>
     */
    private function defaultTree(?User $user, string $surface): array
    {
        $items = [];
        foreach (self::DEFAULT_ITEMS as $item) {
            $showOnMobile = (bool) ($item['show_on_mobile'] ?? true);
            if ($surface === self::SURFACE_MOBILE && ! $showOnMobile) {
                continue;
            }

            $visibility = (string) $item['visibility'];
            $visible = match ($visibility) {
                self::VISIBILITY_AUTHENTICATED => $user instanceof User,
                self::VISIBILITY_PUBLIC_GROUPS_DIRECTORY => GroupDirectory::isEnabled(),
                self::VISIBILITY_MEMBERS_DIRECTORY => MembersDirectory::visibleTo($user),
                default => true,
            };

            $routeName = (string) $item['route_name'];
            if (! $visible || ! Route::has($routeName)) {
                continue;
            }

            $items[] = [
                'title' => (string) $item['title'],
                'url' => route($routeName),
                'icon' => null,
                'opens_new_tab' => false,
                'children' => [],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalise(array $input, ?NavigationItem $existing = null): array
    {
        $linkType = in_array(($input['link_type'] ?? self::LINK_ROUTE), [self::LINK_ROUTE, self::LINK_URL], true)
            ? (string) $input['link_type']
            : self::LINK_ROUTE;

        $visibility = array_key_exists((string) ($input['visibility'] ?? ''), self::VISIBILITY_OPTIONS)
            ? (string) $input['visibility']
            : self::VISIBILITY_EVERYONE;

        $parentId = $this->normaliseParentId($input['parent_id'] ?? null, $existing);
        $icon = trim((string) ($input['icon'] ?? ''));
        if (! in_array($icon, self::ICON_OPTIONS, true)) {
            $icon = '';
        }

        $data = [
            'parent_id' => $parentId,
            'title' => Str::limit(trim((string) ($input['title'] ?? '')), 80, ''),
            'link_type' => $linkType,
            'route_name' => null,
            'url' => null,
            'icon' => $icon !== '' ? $icon : null,
            'is_enabled' => (bool) ($input['is_enabled'] ?? true),
            'show_on_desktop' => (bool) ($input['show_on_desktop'] ?? true),
            'show_on_mobile' => (bool) ($input['show_on_mobile'] ?? true),
            'opens_new_tab' => (bool) ($input['opens_new_tab'] ?? false),
            'visibility' => $visibility,
            'group_ids' => $visibility === self::VISIBILITY_GROUPS ? $this->normaliseGroupIds($input['group_ids'] ?? []) : null,
        ];

        if ($linkType === self::LINK_ROUTE) {
            $routeName = (string) ($input['route_name'] ?? '');
            $data['route_name'] = array_key_exists($routeName, self::ROUTE_OPTIONS) ? $routeName : null;
        } else {
            $data['url'] = $this->safeUrl((string) ($input['url'] ?? ''));
        }

        return $data;
    }

    private function normaliseParentId(mixed $value, ?NavigationItem $existing): ?int
    {
        $parentId = (int) $value;
        if ($parentId <= 0 || ($existing instanceof NavigationItem && $parentId === (int) $existing->id)) {
            return null;
        }

        $parent = NavigationItem::query()->whereKey($parentId)->whereNull('parent_id')->first();

        return $parent instanceof NavigationItem ? (int) $parent->id : null;
    }

    /**
     * @return list<int>
     */
    private function normaliseGroupIds(mixed $value): array
    {
        $ids = is_array($value) ? $value : [];

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[[:cntrl:]]/', $url) === 1) {
            return null;
        }

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            return $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function nextPosition(?int $parentId): int
    {
        return (int) NavigationItem::query()->where('parent_id', $parentId)->max('position') + 1;
    }
}
