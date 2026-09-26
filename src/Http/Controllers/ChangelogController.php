<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
final class ChangelogController
{
    public function index(): void
    {
        App::render('changelog.twig', [
            'title' => 'Changelog',
            'authed' => App::isAuthenticated(),
        ]);
    }
}
