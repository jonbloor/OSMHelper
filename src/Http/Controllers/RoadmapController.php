<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
final class RoadmapController
{
    public function index(): void
    {
        App::render('roadmap.twig', [
            'title' => 'Roadmap',
            'authed' => App::isAuthenticated(),
        ]);
    }
}
