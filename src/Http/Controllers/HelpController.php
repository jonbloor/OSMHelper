<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
final class HelpController
{
    public function index(): void
    {
        App::render('help.twig', [
            'title' => 'Help',
            'authed' => App::isAuthenticated(),
        ]);
    }
}
