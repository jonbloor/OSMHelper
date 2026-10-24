<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\App;
use App\Http\Auth;
use App\Http\Csrf;
use App\Members\YoungLeaderLimits;
use App\Members\YoungLeaders;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use App\Osm\OsmCache;
use App\Osm\OsmTokens;
use Throwable;

/**
 * Young Leaders: the other sections each member of a section helps in.
 * GET  /young-leaders/?section=ID  shows the 24-hour cached copy only (0 OSM calls).
 * POST /young-leaders/load         JSON, one step = at most ONE OSM call (see YoungLeaders::step()).
 * POST /young-leaders/refresh      marks the section's copy for reloading, then the page reloads it.
 */
final class YoungLeadersController
{
    private const FLASH = 'ylFlash';
    // The pause, the Refresh cooldown and Refresh progress live in YoungLeaderLimits, per OSM user,
    // so signing in again doesn't reset them. Only page messages stay in the session.
    private const AUTOSTART = 'ylAutoStart';
    /** Refresh at most once per 5 minutes per section. */
    public const REFRESH_COOLDOWN_SEC = 300;

    public function index(): void
    {
        $token = Auth::requireLogin();
        [$choices, $sectionError] = self::choices($token);
        $sid = self::sectionParam($_GET['section'] ?? null);
        if ($sid === '' && isset($choices[0]) && $choices[0]['isYoungLeaders']) {
            $sid = (string) $choices[0]['id'];
        }
        $section = YoungLeaders::findChoice($choices, $sid);
        $userId = OsmCache::currentUserId();
        $view = $section !== null && $userId !== null
            ? YoungLeaders::view($userId, (string) $section['id'], YoungLeaderLimits::refreshSince($userId, (string) $section['id']))
            : null;
        $flash = $_SESSION[self::FLASH] ?? null;
        unset($_SESSION[self::FLASH]);
        if (!is_array($flash) || ($section !== null && ($flash['section'] ?? '') !== (string) $section['id'])) {
            $flash = null;
        }
        $autostart = $section !== null && ($_SESSION[self::AUTOSTART] ?? '') === (string) $section['id'];
        unset($_SESSION[self::AUTOSTART]);
        $fetchedAt = $view['fetchedAt'] ?? null;
        App::render('young-leaders.twig', Auth::baseContext([
            'title' => 'Young Leaders',
            'groupName' => (string) ($_SESSION['groupName'] ?? 'OSM Helper'),
            'ylChoices' => array_values(array_filter($choices, static fn ($c) => $c['isYoungLeaders'])),
            'otherChoices' => array_values(array_filter($choices, static fn ($c) => !$c['isYoungLeaders'])),
            'sectionError' => $sectionError,
            'section' => $section,
            'badSection' => $sid !== '' && $section === null,
            'view' => $view,
            'fetchedAtText' => is_int($fetchedAt) && $fetchedAt > 0
                ? (new \DateTimeImmutable('@' . $fetchedAt))->setTimezone(new \DateTimeZone('Europe/London'))->format('d/m/y H:i')
                : null,
            'flash' => $flash,
            'autostart' => $autostart,
            'confirmAbove' => YoungLeaders::CONFIRM_ABOVE,
            'maxMembers' => YoungLeaders::MAX_MEMBERS,
            'pauseSeconds' => YoungLeaders::PAUSE_US / 1_000_000,
            'cooldownMinutes' => (int) (self::REFRESH_COOLDOWN_SEC / 60),
            'noUser' => $userId === null,
            // During a backoff the Load/Continue and Refresh buttons are hidden (they'd only be refused).
            'busy' => $section !== null && $userId !== null ? YoungLeaders::busyMessage($userId) : null,
        ]));
    }

    /** One loading step as JSON. Never redirects, so the page's script can always read the answer. */
    public function load(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        $token = OsmTokens::sessionToken();
        if ($token === null) {
            self::json(401, ['state' => 'stopped', 'message' => 'Your sign-in has ended. Sign in again, then press Load to carry on.']);
        }
        if (!Csrf::validate(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
            self::json(403, ['state' => 'stopped', 'message' => 'Security token expired. Reload the page and try again.']);
        }
        [$choices, $sectionError] = self::choices($token);
        $section = YoungLeaders::findChoice($choices, self::sectionParam($_POST['section_id'] ?? null));
        if ($sectionError !== null || $section === null) {
            self::json(400, ['state' => 'stopped', 'message' => $sectionError ?? 'That section is not on your sign-in.']);
        }
        $userId = OsmCache::currentUserId();
        if ($userId === null) {
            self::json(400, ['state' => 'stopped', 'message' => 'OSMHelper could not tell which OSM user you are, so it did not load anything. Sign out and sign in again.']);
        }
        $sid = (string) $section['id'];
        $r = YoungLeaders::step(new OsmApi(), $token, $section, $userId, [
            'lastCallAt' => YoungLeaderLimits::lastCallAt($userId),
            'refreshSince' => YoungLeaderLimits::refreshSince($userId, $sid),
            'sleep' => static function (int $us): void { usleep($us); },
            'now' => static fn (): float => microtime(true),
            'confirmed' => ($_POST['confirmed'] ?? '') === '1',
        ]);
        if ($r['lastCallAt'] !== null) {
            YoungLeaderLimits::setLastCallAt($userId, (float) $r['lastCallAt']);
        }
        if ($r['state'] === 'done') {
            YoungLeaderLimits::endRefresh($userId, $sid);
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'ok', 'message' => 'All ' . $r['total'] . ' members loaded. Search, Helping in, sorting and CSV use this copy (kept for up to 24 hours, deleted when you sign out) and don’t call OSM.'];
        } elseif ($r['state'] === 'stopped') {
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'error', 'message' => (string) $r['message'] . ' Loaded ' . $r['loaded'] . ' of ' . $r['total'] . ' members.'];
        }
        self::json(200, [
            'state' => $r['state'],
            'total' => $r['total'],
            'loaded' => $r['loaded'],
            'calls' => $r['calls'],
            'confirm' => $r['confirm'],
            'message' => $r['message'],
        ]);
    }

    public function refresh(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        [$choices] = self::choices($token);
        $section = YoungLeaders::findChoice($choices, self::sectionParam($_POST['section_id'] ?? null));
        if ($section === null) {
            header('Location: /young-leaders/', true, 303);
            exit;
        }
        $sid = (string) $section['id'];
        $back = '/young-leaders/?section=' . rawurlencode($sid);
        $userId = OsmCache::currentUserId();
        if ($userId === null) {
            header('Location: ' . $back, true, 303);
            exit;
        }
        $wait = self::refreshWait($userId, $sid);
        if ($wait > 0) {
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'error', 'message' => 'This section was refreshed a moment ago. To protect your OSM allowance you can refresh again in ' . (int) ceil($wait / 60) . ' min.'];
            header('Location: ' . $back, true, 303);
            exit;
        }
        // Older copies stay on screen until each member is reloaded (nothing is lost if loading stops).
        // Microseconds, so members fetched earlier in this same second still count as older.
        YoungLeaderLimits::startRefresh($userId, $sid, OsmCache::currentMicrotime());
        $_SESSION[self::AUTOSTART] = $sid;
        header('Location: ' . $back, true, 303);
        exit;
    }

    /** Seconds until this OSM user may press Refresh again for the section (0 = now). */
    public static function refreshWait(string $userId, string $sid): int
    {
        $lastAt = YoungLeaderLimits::lastRefreshAt($userId, $sid);
        if ($lastAt <= 0) {
            return 0;
        }
        return max(0, self::REFRESH_COOLDOWN_SEC - ((int) OsmCache::currentMicrotime() - $lastAt));
    }

    private static function sectionParam(mixed $v): string
    {
        return is_string($v) && preg_match('/^\d{1,12}$/', $v) ? $v : '';
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): never
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** Sections from the sign-in list (0 calls; 1 /oauth/resource read for an older session). @return array{0: list<array<string,mixed>>, 1: ?string} */
    private static function choices(string $token): array
    {
        try {
            return [YoungLeaders::sectionChoices(LoginSections::ensure($token)), null];
        } catch (Throwable) {
            return [[], 'Could not read the sections from this login. Sign out and connect again.'];
        }
    }
}
