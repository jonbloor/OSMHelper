<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Config;

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

        App::render('home.twig', [
            'title' => 'OSMHelper',
            'authed' => $authed,
            'fullName' => $_SESSION['fullName'] ?? null,
            'groupName' => $_SESSION['groupName'] ?? null,
        ]);
    }
}
