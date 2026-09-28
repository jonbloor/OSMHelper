<?php
declare(strict_types=1);
namespace App\Http\Controllers;
/**
 * The Changelog now lives on the Roadmap page (#history). Old /changelog/ links and bookmarks
 * get a permanent redirect, done here so it works whatever the web server config is.
 *
 * The Location has no fragment on purpose: browsers carry the original fragment across a
 * fragment-less redirect, so /changelog/#2026-09-26 lands on /roadmap/?from=changelog#2026-09-26.
 * With no fragment, a small script on the roadmap page jumps to #history when ?from=changelog.
 */
final class ChangelogController
{
    public function index(): void
    {
        header('Location: /roadmap/?from=changelog', true, 301);
    }
}
