<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;

final class DashboardController
{
    public function index(): void
    {
        if (!App::isAuthenticated()) {
            header('Location: /auth');
            exit;
        }

        $sectionsCount = isset($_SESSION['_sections_count'])
            ? (int) $_SESSION['_sections_count']
            : null;

        App::render('dashboard.twig', [
            'title' => 'Dashboard',
            'authed' => true,
            'fullName' => $_SESSION['fullName'] ?? 'Unknown User',
            'email' => $_SESSION['email'] ?? 'Unknown Email',
            'groupName' => $_SESSION['groupName'] ?? 'OSM Helper',
            'sectionsCount' => $sectionsCount,
        ]);
    }
}
