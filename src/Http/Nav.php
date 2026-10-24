<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The main menu, defined once. layout.twig renders it; Help and tests read the same labels.
 *
 * Labels and grouping only: every href here is an existing route. Roadmap is not in the
 * menu; it is linked from the footer and from the top of Help.
 */
final class Nav
{
    /** @return list<array<string, mixed>> */
    public static function signedIn(): array
    {
        return [
            ['type' => 'link', 'key' => 'home', 'label' => 'Home', 'href' => '/dashboard/', 'icon' => 'home'],
            ['type' => 'group', 'key' => 'people', 'label' => 'People', 'icon' => 'people', 'items' => [
                ['label' => 'Group numbers', 'href' => '/membership-dashboard/'],
                ['label' => 'Member list', 'href' => '/members/'],
                ['label' => 'Find a member', 'href' => '/find-member/'],
                ['label' => 'Young Leaders', 'href' => '/young-leaders/'],
                ['label' => 'Member checks', 'href' => '/member-checks/'],
            ]],
            ['type' => 'group', 'key' => 'joining', 'label' => 'Joining', 'icon' => 'joining', 'items' => [
                ['label' => 'Waiting list', 'href' => '/waiting-list/'],
                ['label' => 'Joining form', 'href' => '/wordpress-form/'],
            ]],
            ['type' => 'group', 'key' => 'badges', 'label' => 'Badges', 'icon' => 'badges', 'items' => [
                ['label' => 'Events', 'href' => '/events/'],
                ['label' => 'Nights away', 'href' => '/nights-away/'],
                ['label' => 'Top awards', 'href' => '/top-awards/'],
            ]],
            ['type' => 'group', 'key' => 'equipment', 'label' => 'Equipment', 'icon' => 'equipment', 'items' => [
                ['label' => 'Equipment list', 'href' => '/equipment/'],
                ['label' => 'Add an item', 'href' => '/equipment/#add-item'],
                ['label' => 'Locations', 'href' => '/settings/#equipment-locations'],
            ]],
            ['type' => 'group', 'key' => 'finance', 'label' => 'Finance', 'icon' => 'finance', 'items' => [
                ['label' => 'Bank accounts', 'href' => '/bank-transfers/'],
                ['label' => 'All transfers', 'href' => '/bank-transfers/all/'],
            ]],
            ['type' => 'link', 'key' => 'settings', 'label' => 'Settings', 'href' => '/settings/', 'icon' => 'settings'],
            ['type' => 'link', 'key' => 'help', 'label' => 'Help', 'href' => '/help/', 'icon' => 'help'],
            ['type' => 'link', 'key' => 'logout', 'label' => 'Sign out', 'href' => '/logout/', 'icon' => 'logout'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function public(): array
    {
        return [
            ['type' => 'link', 'key' => 'home', 'label' => 'Home', 'href' => '/', 'icon' => 'home'],
            ['type' => 'link', 'key' => 'help', 'label' => 'Help', 'href' => '/help/', 'icon' => 'help'],
        ];
    }

    /**
     * The menu for this request, with the current page marked: 'current' on the one matching
     * link and 'hasCurrent' on its group. Done here (not in JS) so it also works without JS.
     *
     * @return list<array<string, mixed>>
     */
    public static function forRequest(bool $authed, ?string $requestUri = null): array
    {
        $entries = $authed ? self::signedIn() : self::public();
        $uri = $requestUri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return self::markCurrent($entries, (string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public static function markCurrent(array $entries, string $path): array
    {
        $best = self::currentHref($entries, $path);
        foreach ($entries as &$entry) {
            if ($entry['type'] === 'group') {
                $entry['hasCurrent'] = false;
                foreach ($entry['items'] as &$item) {
                    $item['current'] = $best !== null && $item['href'] === $best;
                    if ($item['current']) {
                        $entry['hasCurrent'] = true;
                    }
                }
                unset($item);
            } else {
                $entry['current'] = $best !== null && $entry['href'] === $best;
            }
        }
        unset($entry);

        return $entries;
    }

    /**
     * The single menu href for $path: an exact match, or the longest href whose path is a
     * whole-segment prefix (/waiting-list/rank -> /waiting-list/, but /membership-dashboard/
     * is not under /members/). Links with a #fragment point into a page and never count.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function currentHref(array $entries, string $path): ?string
    {
        $path = self::normalise($path);
        $best = null;
        $bestLen = -1;
        foreach (self::hrefs($entries) as $href) {
            if (str_contains($href, '#')) {
                continue;
            }
            $p = self::normalise((string) parse_url($href, PHP_URL_PATH));
            $hit = $p === $path || ($p !== '/' && str_starts_with($path, $p . '/'));
            if ($hit && strlen($p) > $bestLen) {
                $best = $href;
                $bestLen = strlen($p);
            }
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    public static function hrefs(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'group') {
                foreach ($entry['items'] as $item) {
                    $out[] = (string) $item['href'];
                }
            } else {
                $out[] = (string) $entry['href'];
            }
        }

        return $out;
    }

    private static function normalise(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : $path;
    }
}
