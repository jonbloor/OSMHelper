<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Http\Auth;
use App\Osm\OsmErrorLog;
final class HomeController
{
    public function index(): void
    {
        if (!Config::isConfigured()) {
            App::render('setup.twig', [
                'title' => 'Setup required',
                'missing' => Config::missingRequired(),
            ]);
            return;
        }

        $authed = App::isAuthenticated();
        $ctx = [
            'title' => 'OSMHelper',
            'authed' => $authed,
            'fullName' => $_SESSION['fullName'] ?? null,
            'groupName' => $_SESSION['groupName'] ?? null,
            'osmRecentErrors' => [],
        ];
        if (!$authed) {
            $ctx['pageTitle'] = 'OSMHelper: waiting list, badges and group admin tools for OSM';
            $ctx['metaDescription'] = 'An independent helper for Online Scout Manager, built by a Scout leader. Rank your waiting list, check group numbers, track top awards and nights away, and tidy equipment, using your own OSM login.';
            $ctx['bodyClass'] = 'page-home';
        }
        if ($authed) {
            $ctx = array_merge($ctx, Auth::ensureRateLimit());
            // Admins (ADMIN_OSM_USER_IDS) see every group's errors; everyone else only their own sections'.
            if (Config::isAdmin($_SESSION['osmUserId'] ?? null)) {
                $ctx['osmRecentErrors'] = OsmErrorLog::recent(8);
                $ctx['osmErrorsScope'] = 'all';
            } else {
                $ids = $_SESSION['osmSectionIds'] ?? [];
                $ctx['osmRecentErrors'] = OsmErrorLog::recentForSections(is_array($ids) ? array_map('strval', $ids) : [], 8);
                $ctx['osmErrorsScope'] = 'mine';
            }
        }
        App::render('home.twig', $ctx);
    }
}
