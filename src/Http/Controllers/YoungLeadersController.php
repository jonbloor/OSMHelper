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
 * GET  /young-leaders/?section=ID  shows the 2-hour cached copy only (0 OSM calls).
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
        $limitsError = $section !== null && $userId !== null && !YoungLeaderLimits::looksSavable($userId) ? YoungLeaderLimits::UNAVAILABLE_MESSAGE : null;
        if ($limitsError !== null && is_array($flash) && ($flash['message'] ?? '') === $limitsError) {
            $flash = null; // said once, below the progress bar
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
            // The limits can't be saved: loading is refused (fail closed), so the buttons are hidden too.
            'limitsError' => $limitsError,
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
        $r = self::loadStep(new OsmApi(), $token, $section, $userId, ($_POST['confirmed'] ?? '') === '1');
        self::json(200, [
            'state' => $r['state'],
            'total' => $r['total'],
            'loaded' => $r['loaded'],
            'calls' => $r['calls'],
            'confirm' => $r['confirm'],
            'message' => $r['message'],
        ]);
    }

    /**
     * One loading step for this OSM user, with the limits read from and saved to YoungLeaderLimits.
     * Fails closed: if the limits can't be saved, OSM is not contacted (calls 0) and the page says why.
     *
     * @param array<string, mixed> $section
     * @param array{sleep?:callable(int):void, now?:callable():float} $clock tests only
     * @return array{state:string,total:int,loaded:int,calls:int,message:?string,lastCallAt:?float,confirm:bool}
     */
    public static function loadStep(OsmApi $api, string $token, array $section, string $userId, bool $confirmed, array $clock = []): array
    {
        $sid = (string) $section['id'];
        if (!YoungLeaderLimits::canSave($userId)) {
            $v = YoungLeaders::view($userId, $sid, YoungLeaderLimits::refreshSince($userId, $sid));
            $r = ['state' => 'stopped', 'total' => $v['total'], 'loaded' => $v['total'] - $v['pending'], 'calls' => 0,
                'message' => YoungLeaderLimits::UNAVAILABLE_MESSAGE, 'lastCallAt' => null, 'confirm' => false];
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'error', 'message' => YoungLeaderLimits::UNAVAILABLE_MESSAGE];
            return $r;
        }
        $now = $clock['now'] ?? static fn (): float => microtime(true);
        $r = YoungLeaders::step($api, $token, $section, $userId, [
            'lastCallAt' => YoungLeaderLimits::lastCallAt($userId),
            'refreshSince' => YoungLeaderLimits::refreshSince($userId, $sid),
            'sleep' => $clock['sleep'] ?? static function (int $us): void { usleep($us); },
            'now' => $now,
            'confirmed' => $confirmed,
            // The next call slot is reserved (and saved) before the pause, under the limits file's lock.
            'reserve' => static fn (int $wantUs): ?int => YoungLeaderLimits::reserveCall($userId, $wantUs, $now()),
        ]);
        if ($r['state'] === 'done') {
            YoungLeaderLimits::endRefresh($userId, $sid);
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'ok', 'message' => 'All ' . $r['total'] . ' members loaded. Search, Helping in, sorting and CSV use this copy (kept for up to 2 hours, deleted when you sign out) and don’t call OSM.'];
        } elseif ($r['state'] === 'stopped') {
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'error', 'message' => (string) $r['message'] . ' Loaded ' . $r['loaded'] . ' of ' . $r['total'] . ' members.'];
        }
        return $r;
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
        $msg = self::startRefreshFor($userId, $sid);
        if ($msg !== null) {
            $_SESSION[self::FLASH] = ['section' => $sid, 'kind' => 'error', 'message' => $msg];
        } else {
            $_SESSION[self::AUTOSTART] = $sid;
        }
        header('Location: ' . $back, true, 303);
        exit;
    }

    /**
     * Start a Refresh for this OSM user and section, or say why not (null = started). Fails closed:
     * refused while the 5-minute cooldown runs, and refused when the limits can't be saved (so the
     * cooldown can't be skipped).
     */
    public static function startRefreshFor(string $userId, string $sid): ?string
    {
        if (!YoungLeaderLimits::canSave($userId)) {
            return YoungLeaderLimits::UNAVAILABLE_MESSAGE;
        }
        $wait = self::refreshWait($userId, $sid);
        if ($wait > 0) {
            return 'This section was refreshed a moment ago. To protect your OSM allowance you can refresh again in ' . (int) ceil($wait / 60) . ' min.';
        }
        // Older copies stay on screen until each member is reloaded (nothing is lost if loading stops).
        // Microseconds, so members fetched earlier in this same second still count as older.
        // Checked again and saved under the lock, in case another tab started one just now.
        $started = YoungLeaderLimits::startRefreshIfDue($userId, $sid, OsmCache::currentMicrotime(), self::REFRESH_COOLDOWN_SEC);
        if ($started === null) {
            return YoungLeaderLimits::UNAVAILABLE_MESSAGE;
        }
        if (!$started) {
            return 'This section was refreshed a moment ago. To protect your OSM allowance you can refresh again in ' . (int) ceil(max(1, self::refreshWait($userId, $sid)) / 60) . ' min.';
        }
        return null;
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
