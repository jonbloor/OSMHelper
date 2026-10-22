<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Events\EventList;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use Throwable;

/**
 * Events: one section's events (including archived) for a date range or all time.
 * GET /events and GET /events/csv only read the session copy (no OSM calls).
 * POST /events/refresh loads one section from OSM (two requests, see EventList::load).
 */
final class EventsController
{
    public function index(): void
    {
        $token = Auth::requireLogin();
        [$choices, $sectionError] = self::choices($token);
        $sectionId = self::pickSectionId($choices, (string) ($_GET['section'] ?? ''));
        [$fromIso, $toIso, $rangeError, $allTime, $swapped] = self::range($_GET);
        $cache = $sectionId !== null ? EventList::cached($sectionId) : null;
        $events = null;
        if ($cache !== null) {
            $events = EventList::filter($cache['events'], $fromIso, $toIso);
        }
        $flash = $_SESSION['eventsFlash'] ?? null;
        unset($_SESSION['eventsFlash']);
        $groups = LoginSections::groupChoices($choices);
        $sectionName = '';
        foreach ($choices as $c) {
            if ($c['id'] === $sectionId) {
                $sectionName = $c['name'];
            }
        }
        $rows = [];
        foreach ($events ?? [] as $e) {
            $rows[] = $e + ['uk' => EventList::ukDate($e['date'])];
        }
        $wait = $sectionId !== null ? EventList::cooldownRemaining($sectionId) : 0;
        App::render('events.twig', Auth::baseContext([
            'title' => 'Events',
            'groupName' => (string) ($_SESSION['groupName'] ?? 'OSM Helper'),
            'groups' => $groups,
            'multiGroup' => count($groups) > 1,
            'choiceCount' => count($choices),
            'sectionError' => $sectionError,
            'sectionId' => $sectionId,
            'sectionName' => $sectionName,
            'cache' => $cache === null ? null : [
                'builtAt' => $cache['builtAt'],
                'total' => count($cache['events']),
                'archived' => count(array_filter($cache['events'], static fn ($e) => $e['archived'])),
            ],
            'events' => $events === null ? null : $rows,
            'allTime' => $allTime,
            'from' => EventList::ukDate($fromIso),
            'to' => EventList::ukDate($toIso),
            'fromRaw' => $rangeError !== null ? (string) ($_GET['from'] ?? '') : EventList::ukDate($fromIso),
            'toRaw' => $rangeError !== null ? (string) ($_GET['to'] ?? '') : EventList::ukDate($toIso),
            'rangeError' => $rangeError,
            'rangeNote' => $swapped ? 'The From date was after the To date, so they were swapped: showing ' . EventList::ukDate($fromIso) . ' to ' . EventList::ukDate($toIso) . '.' : null,
            'csvQuery' => http_build_query(array_filter([
                'section' => $sectionId,
                'range' => $allTime ? 'all' : 'dates',
                'from' => EventList::ukDate($fromIso),
                'to' => EventList::ukDate($toIso),
            ], static fn ($v) => $v !== null && $v !== '')),
            'flash' => is_array($flash) ? $flash : null,
            'cooldownMinutes' => (int) (EventList::REFRESH_COOLDOWN_SEC / 60),
            'cooldownWait' => $wait > 0 ? (int) ceil($wait / 60) : 0,
        ]));
    }

    public function refresh(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $postedId = is_string($_POST['section_id'] ?? null) ? trim($_POST['section_id']) : '';
        // Cooldown first, before anything that could call OSM (an older session without stored
        // sections would otherwise read /oauth/resource just to be told to wait).
        $earlyWait = ($postedId !== '' && strlen($postedId) <= 20) ? EventList::cooldownRemaining($postedId) : 0;
        if ($earlyWait > 0) {
            $_SESSION['eventsFlash'] = ['kind' => 'error', 'message' => 'Events for this section were loaded a moment ago. To protect your OSM allowance you can load again in ' . (int) ceil($earlyWait / 60) . ' min.'];
            header('Location: /events/?' . http_build_query(['section' => $postedId]));
            exit;
        }
        [$choices, $sectionError] = self::choices($token);
        $sectionId = self::pickSectionId($choices, $postedId);
        $back = static function (string $kind, string $msg) use (&$sectionId): never {
            $_SESSION['eventsFlash'] = ['kind' => $kind, 'message' => $msg];
            $q = [];
            if ($sectionId !== null) {
                $q['section'] = $sectionId;
            }
            foreach (['range', 'from', 'to'] as $k) {
                if (isset($_POST[$k]) && is_string($_POST[$k]) && $_POST[$k] !== '') {
                    $q[$k] = mb_substr($_POST[$k], 0, 20);
                }
            }
            header('Location: /events/' . ($q !== [] ? '?' . http_build_query($q) : ''));
            exit;
        };
        if ($sectionError !== null) {
            $back('error', $sectionError);
        }
        if ($sectionId === null || (string) ($_POST['section_id'] ?? '') !== $sectionId) {
            $sectionId = null;
            $back('error', 'Choose a section, then load its events.');
        }
        $wait = EventList::cooldownRemaining($sectionId);
        if ($wait > 0) {
            $back('error', 'Events for this section were loaded a moment ago. To protect your OSM allowance you can load again in ' . (int) ceil($wait / 60) . ' min.');
        }
        $name = $sectionId;
        foreach ($choices as $c) {
            if ($c['id'] === $sectionId) {
                $name = $c['name'];
            }
        }
        EventList::markRefreshed($sectionId);
        $res = EventList::load(new OsmApi(), $token, ['section_id' => $sectionId, 'section_name' => $name]);
        $note = $res['rateUnknown'] ? ' OSM did not report your remaining allowance, so OSMHelper paused longer between requests.' : '';
        if ($res['events'] === null) {
            $kept = EventList::cached($sectionId) !== null;
            $back('error', (string) $res['stopReason'] . ($kept ? ' Your earlier copy of these events is still shown.' : '') . $note);
        }
        EventList::store($sectionId, $name, $res['events'], $res['calls']);
        $back('ok', 'Loaded ' . count($res['events']) . ' event(s) for ' . $name . ' using ' . $res['calls']
            . ' OSM request(s). Changing the dates or downloading the CSV uses this copy and does not call OSM.' . $note);
    }

    /** CSV of the filtered cached list. Never calls OSM. */
    public function csv(): void
    {
        $token = Auth::requireLogin();
        [$choices] = self::choices($token);
        $sectionId = self::pickSectionId($choices, (string) ($_GET['section'] ?? ''));
        [$fromIso, $toIso, $rangeError] = self::range($_GET);
        $cache = $sectionId !== null ? EventList::cached($sectionId) : null;
        if ($cache === null || $rangeError !== null) {
            $_SESSION['eventsFlash'] = ['kind' => 'error', 'message' => $rangeError ?? 'There are no events loaded for this section yet. Choose Load events first, then download the CSV.'];
            header('Location: /events/' . ($sectionId !== null ? '?section=' . rawurlencode($sectionId) : ''));
            exit;
        }
        $name = (string) $cache['sectionName'];
        $body = EventList::csv($name, EventList::filter($cache['events'], $fromIso, $toIso));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . EventList::csvFilename($name, $fromIso, $toIso) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo $body;
    }

    /**
     * From the query: [fromIso, toIso, error, allTime, swapped]. Default and range=all mean all time.
     * Accepts dd/mm/yyyy (shown to users) and yyyy-mm-dd. swapped = From was after To.
     * @param array<string, mixed> $q
     * @return array{0:?string,1:?string,2:?string,3:bool,4:bool}
     */
    public static function range(array $q): array
    {
        $range = is_string($q['range'] ?? null) ? $q['range'] : 'all';
        if ($range !== 'dates') {
            return [null, null, null, true, false];
        }
        $fromRaw = is_string($q['from'] ?? null) ? trim($q['from']) : '';
        $toRaw = is_string($q['to'] ?? null) ? trim($q['to']) : '';
        $from = $fromRaw === '' ? null : EventList::isoDate($fromRaw);
        $to = $toRaw === '' ? null : EventList::isoDate($toRaw);
        if (($fromRaw !== '' && $from === null) || ($toRaw !== '' && $to === null)) {
            return [null, null, 'Dates must be written dd/mm/yyyy, for example 01/09/2026. Showing all time instead.', true, false];
        }
        if ($from === null && $to === null) {
            return [null, null, null, true, false];
        }
        $swapped = false;
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
            $swapped = true;
        }
        return [$from, $to, null, false, $swapped];
    }

    /** @param list<array{id:string}> $choices */
    private static function pickSectionId(array $choices, string $want): ?string
    {
        foreach ($choices as $c) {
            if ($c['id'] === $want) {
                return $c['id'];
            }
        }
        return null;
    }

    /** @return array{0: list<array<string,mixed>>, 1: ?string} */
    private static function choices(string $token): array
    {
        try {
            return [LoginSections::memberListChoices(LoginSections::ensure($token)), null];
        } catch (Throwable) {
            return [[], 'Could not read the sections from this login. Sign out and connect again.'];
        }
    }
}
