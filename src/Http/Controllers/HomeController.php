<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Osm\OsmApi;
use Throwable;
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
        $rateLimit = null;
        $rateResetText = null;

        if ($authed) {
            $token = (string) ($_SESSION['accessToken'] ?? '');
            $rateLimit = OsmApi::sessionSnapshot();
            if ($rateLimit === null && $token !== '') {
                try {
                    $api = new OsmApi();
                    $api->get($token, '/oauth/resource');
                    $rateLimit = OsmApi::sessionSnapshot();
                } catch (Throwable) {
                    $rateLimit = OsmApi::sessionSnapshot();
                }
            }
            if (is_array($rateLimit)) {
                $secs = $rateLimit['secondsUntilReset'];
                if ($secs === null) {
                    $rateResetText = 'Unknown';
                } elseif ($secs <= 0) {
                    $rateResetText = 'now';
                } else {
                    $mins = (int) ceil($secs / 60);
                    $rateResetText = $mins . ' minute' . ($mins === 1 ? '' : 's');
                }
            }
        }

        App::render('home.twig', [
            'title' => 'OSMHelper',
            'authed' => $authed,
            'fullName' => $_SESSION['fullName'] ?? null,
            'groupName' => $_SESSION['groupName'] ?? null,
            'rateLimit' => $rateLimit,
            'rateResetText' => $rateResetText,
        ]);
    }
}
