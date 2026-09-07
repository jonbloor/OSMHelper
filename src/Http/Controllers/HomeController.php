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
        if ($authed) {
            $ctx = array_merge($ctx, Auth::ensureRateLimit());
            $ctx['osmRecentErrors'] = OsmErrorLog::recent(8);
        }
        App::render('home.twig', $ctx);
    }
}
