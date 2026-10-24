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

    /** @return list<array<string, mixed>> */
    public static function forRequest(bool $authed): array
    {
        return $authed ? self::signedIn() : self::public();
    }
}
