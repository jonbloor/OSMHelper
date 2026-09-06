<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Http\Auth;
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
        ];
        if ($authed) {
            $ctx = array_merge($ctx, Auth::ensureRateLimit());
        }
        App::render('home.twig', $ctx);
    }
}
