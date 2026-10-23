<?php
declare(strict_types=1);
namespace App\Events;
use App\Osm\OsmApi;
use App\Osm\OsmErrorLog;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Events: one section's event list (including archived events), held in the session.
 *
 * Only load() calls OSM, and only two GET requests per section, never one per event:
 *   1. /ext/events/summary/?action=getEvents&sectionid=ID                 (events, no term filter)
 *   2. /ext/events/summary/?action=getEvents&sectionid=ID&onlyArchived=1&showArchived=true
 *      (asks for archived events; onlyArchived is in community docs, showArchived in the older API)
 * Changing the date range, sorting and the CSV download all use the cached copy (no OSM calls).
 * The load stops at the first error, 429, X-Blocked or unusable 200, and before each call if the
 * OSM allowance is low. There are no retries.
 */
final class EventList
{
    public const ENDPOINT = '/ext/events/summary/';
    public const SESSION_KEY = 'eventsCache';
    public const REFRESH_KEY = 'eventsLastRefresh';
    /** Minimum gap between loads of the same section for one session. */
    public const REFRESH_COOLDOWN_SEC = 300;
    /** Stop before the next call at or below this many remaining requests (same as Find a member). */
    public const RATE_LOW_REMAINING = 60;
    /** Pause between the two calls. */
    private const CALL_DELAY_US = 300000;
    /** Longer pause when OSM sent no X-RateLimit headers (allowance unknown). */
    private const CALL_DELAY_UNKNOWN_US = 1500000;
    /** Cached event lists are kept for at most this long. */
    public const CACHE_TTL_SEC = 7200;
    /** At most this many sections cached per session (oldest dropped). */
    public const MAX_CACHED_SECTIONS = 12;
    /** OAuth scope the events endpoint needs (see Config::OAUTH_SCOPES). */
    public const SCOPE = 'section:event:read';
    public const SCOPE_MESSAGE = 'OSM hasn\'t given OSMHelper permission to read events for this login. Sign out and sign in again, and allow Events when OSM asks.';
    /** Session flag set when OSM refused events access (cleared at sign-in). */
    public const SCOPE_DENIED_KEY = 'eventsScopeDenied';

    /**
     * SCOPE_MESSAGE when this session can't read events, without calling OSM: OSM already refused,
     * or the granted scopes stored at sign-in (else the scopes requested then) lack section:event:read.
     * null = fine to try.
     */
    public static function scopeHint(): ?string
    {
        if (!empty($_SESSION[self::SCOPE_DENIED_KEY])) {
            return self::SCOPE_MESSAGE;
        }
        $granted = $_SESSION['grantedScopes'] ?? null;
        if (is_string($granted) && trim($granted) !== '') {
            return self::hasScope($granted) ? null : self::SCOPE_MESSAGE;
        }
        $requested = $_SESSION['requestedScopes'] ?? null;
        if (is_string($requested) && trim($requested) !== '') {
            return self::hasScope($requested) ? null : self::SCOPE_MESSAGE;
        }
        return self::SCOPE_MESSAGE; // signed in before scopes were recorded
    }

    private static function hasScope(string $scopes): bool
    {
        $list = preg_split('/[\s,]+/', strtolower($scopes)) ?: [];
        return in_array(self::SCOPE, $list, true) || in_array('section:event:write', $list, true);
    }

    /** OSM text that means "this login may not read this" (scope or role). */
    public static function looksLikeAccessRefusal(string $text): bool
    {
        $m = strtolower($text);
        return str_contains($m, 'access to this resource') || str_contains($m, 'permission') || str_contains($m, 'scope');
    }

    /**
     * Parse one getEvents / get response into event rows. 'archived' comes only from the row's own
     * archived field: true/false, or null when the row has no such field.
     *
     * @param array<string, mixed> $res
     * @return list<array{id:string,name:string,date:?string,archived:?bool}>
     */
    public static function parseItems(array $res): array
    {
        $items = $res['items'] ?? ($res['data']['items'] ?? null);
        if ($items === null && array_is_list($res)) {
            $items = $res; // bare list
        }
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (string) ($it['eventid'] ?? $it['event_id'] ?? $it['id'] ?? '');
            $name = trim((string) ($it['name'] ?? ''));
            if ($id === '' && $name === '') {
                continue;
            }
            $date = null;
            foreach (['startdate_g', 'startdate', 'start_date', 'date'] as $k) {
                if (isset($it[$k]) && is_scalar($it[$k])) {
                    $date = self::isoDate((string) $it[$k]);
                    if ($date !== null) {
                        break;
                    }
                }
            }
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('Event ' . $id),
                'date' => $date,
                'archived' => array_key_exists('archived', $it) && $it['archived'] !== null && $it['archived'] !== ''
                    ? self::truthy($it['archived']) : null,
            ];
        }
        return $out;
    }

    /**
     * Merge the main list and the archived-request list: one row per event id.
     *
     * Archived = the row's own archived field (true if either copy says so). Only when OSM sent no
     * archived field on any copy of that event do we fall back to: "returned by the archived request
     * but NOT by the normal list". If OSM ignored the archive params, both replies are the same and
     * nothing is marked from this fallback.
     *
     * @param list<array{id:string,name:string,date:?string,archived:?bool}> $main
     * @param list<array{id:string,name:string,date:?string,archived:?bool}> $archived
     * @return list<array{id:string,name:string,date:?string,archived:bool}>
     */
    public static function merge(array $main, array $archived): array
    {
        $by = [];
        $noId = [];
        $inMain = [];
        foreach ($main as $e) {
            if ($e['id'] !== '') {
                $inMain[$e['id']] = true;
            }
        }
        foreach (array_merge($main, $archived) as $e) {
            if ($e['id'] === '') {
                $noId[] = $e;
                continue;
            }
            if (isset($by[$e['id']])) {
                $prev = $by[$e['id']]['archived'];
                if ($e['archived'] !== null) {
                    $by[$e['id']]['archived'] = ($prev ?? false) || $e['archived'];
                }
                if ($by[$e['id']]['date'] === null) {
                    $by[$e['id']]['date'] = $e['date'];
                }
                continue;
            }
            $by[$e['id']] = $e;
        }
        foreach ($by as $id => $e) {
            if ($e['archived'] === null) {
                $by[$id]['archived'] = !isset($inMain[$id]); // only in the archived reply
            }
        }
        foreach ($noId as $i => $e) {
            $noId[$i]['archived'] = (bool) $e['archived'];
        }
        return self::sort(array_merge(array_values($by), $noId));
    }

    /**
     * Date ascending, then name. Events without a usable date go last.
     * @param list<array{id:string,name:string,date:?string,archived:bool}> $events
     * @return list<array{id:string,name:string,date:?string,archived:bool}>
     */
    public static function sort(array $events): array
    {
        usort($events, static function (array $a, array $b): int {
            $ad = $a['date'] ?? '9999-99-99';
            $bd = $b['date'] ?? '9999-99-99';
            return [$ad, mb_strtolower($a['name']), $a['id']] <=> [$bd, mb_strtolower($b['name']), $b['id']];
        });
        return $events;
    }

    /**
     * Local date-range filter (inclusive). null from/to = open-ended; both null = all time.
     * Undated events only appear for all time.
     * @param list<array{id:string,name:string,date:?string,archived:bool}> $events
     * @return list<array{id:string,name:string,date:?string,archived:bool}>
     */
    public static function filter(array $events, ?string $fromIso, ?string $toIso): array
    {
        if ($fromIso === null && $toIso === null) {
            return array_values($events);
        }
        return array_values(array_filter($events, static function (array $e) use ($fromIso, $toIso): bool {
            if ($e['date'] === null) {
                return false;
            }
            return ($fromIso === null || $e['date'] >= $fromIso) && ($toIso === null || $e['date'] <= $toIso);
        }));
    }

    /** yyyy-mm-dd from yyyy-mm-dd[...] or dd/mm/yyyy, or null. */
    public static function isoDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($y < 1900 || !checkdate($mo, $d, $y)) {
            return null; // includes OSM's 0000-00-00
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** dd/mm/yyyy for display, or '' . */
    public static function ukDate(?string $iso): string
    {
        if ($iso === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return '';
        }
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'y', 'yes'], true);
        }
        return false;
    }

    /**
     * Load one section from OSM: at most two GETs, throttled, stopping at the first problem.
     * @param array{section_id:string,section_name:string} $section
     * @return array{events:?list<array{id:string,name:string,date:?string,archived:bool}>,stopReason:?string,calls:int,rateUnknown:bool}
     */
    public static function load(OsmApi $api, string $token, array $section): array
    {
        $sid = (string) $section['section_id'];
        $name = (string) $section['section_name'];
        $calls = 0;
        $rateUnknown = false;
        $lists = [];
        foreach ([false, true] as $archivedCall) {
            if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
                return self::fail('Stopped to protect your OSM allowance (few requests left). Try again after it resets.', $calls, $rateUnknown);
            }
            if ($calls > 0) {
                usleep($rateUnknown ? self::CALL_DELAY_UNKNOWN_US : self::CALL_DELAY_US);
            }
            $query = ['action' => 'getEvents', 'sectionid' => $sid];
            if ($archivedCall) {
                $query['onlyArchived'] = '1';
                $query['showArchived'] = 'true';
            }
            try {
                $calls++;
                $res = $api->get($token, self::ENDPOINT, $query);
            } catch (Throwable $e) {
                if ($e instanceof \App\Osm\OsmBlockedException) {
                    $msg = 'OSM has blocked requests from OSMHelper, so it stopped straight away. Do not retry; contact OSM support.';
                } elseif ($e->getCode() === 429) {
                    $ra = $api->getRetryAfterSeconds();
                    $msg = 'OSM said too many requests, so OSMHelper stopped. '
                        . ($ra !== null ? 'Try again in about ' . self::humanWait($ra) . '.' : 'Wait for your allowance to reset.');
                } elseif (str_starts_with($e->getMessage(), 'OSM_BLOCKED')) {
                    $msg = 'OSM has blocked requests from OSMHelper, so it stopped straight away. Do not retry; contact OSM support.';
                } elseif (in_array($e->getCode(), [401, 403], true) || self::looksLikeAccessRefusal($e->getMessage())) {
                    if ($e->getCode() !== 401) {
                        $_SESSION[self::SCOPE_DENIED_KEY] = true; // 401 can just be an expired token
                    }
                    return ['scopeDenied' => true] + self::fail(self::SCOPE_MESSAGE, $calls, $rateUnknown);
                } else {
                    $msg = 'OSM returned an error for ' . $name . ($archivedCall ? ' (archived events)' : '') . ', so OSMHelper stopped without trying again.';
                }
                return self::fail($msg, $calls, $rateUnknown);
            }
            if (!$api->lastResponseHadRateHeaders()) {
                $rateUnknown = true;
            }
            $bad = self::badResponseReason($res, $api->lastBodyKind());
            if ($bad !== null) {
                self::logBadReply($sid, $archivedCall, $bad);
                if (self::reasonCode($bad) === 'osm-error' && self::looksLikeAccessRefusal($bad)) {
                    $_SESSION[self::SCOPE_DENIED_KEY] = true;
                    return ['scopeDenied' => true] + self::fail(self::SCOPE_MESSAGE, $calls, $rateUnknown);
                }
                return self::fail('OSM sent an unexpected reply for ' . $name . ($archivedCall ? ' (archived events)' : '')
                    . ' (' . $bad . '), so OSMHelper stopped without trying again.', $calls, $rateUnknown);
            }
            $lists[] = self::parseItems($res);
        }
        return ['events' => self::merge($lists[0], $lists[1]), 'stopReason' => null, 'calls' => $calls, 'rateUnknown' => $rateUnknown, 'scopeDenied' => false];
    }

    /**
     * Record an unusable 200 so it can be diagnosed later. Logs only a fixed reason code and the
     * section id: never OSM's own error text, reply bodies or names.
     */
    private static function logBadReply(string $sectionId, bool $archivedCall, string $reason): void
    {
        $code = self::reasonCode($reason);
        $sid = ctype_digit($sectionId) ? $sectionId : '?';
        error_log('OSMHelper Events: unusable 200 from ' . self::ENDPOINT . ' (section ' . $sid
            . ($archivedCall ? ', archived request' : '') . '): ' . $code . '. Stopped, no retry.');
        try {
            OsmErrorLog::log([
                'method' => 'GET',
                'endpoint' => self::ENDPOINT,
                'action' => 'getEvents',
                'http_status' => 200,
                'osm_code' => $code,
                'kind' => 'bad-200',
                'section_id' => $sid,
                'badge_id' => null,
                'detail' => $archivedCall ? 'archived request' : 'events request',
            ]);
        } catch (Throwable) {
        }
    }

    /** Fixed log code for a badResponseReason() string (drops any OSM-supplied text). */
    public static function reasonCode(string $reason): string
    {
        $head = strtolower(trim(explode(':', $reason, 2)[0]));
        $codes = [
            'not json' => 'not-json',
            'error' => 'osm-error',
            'status false' => 'status-false',
            'empty reply' => 'empty-reply',
            'empty object' => 'empty-object',
            'no event list' => 'no-event-list',
        ];
        return $codes[$head] ?? 'other';
    }

    /** @return array{events:null,stopReason:string,calls:int,rateUnknown:bool,scopeDenied:bool} */
    private static function fail(string $reason, int $calls, bool $rateUnknown): array
    {
        return ['events' => null, 'stopReason' => $reason, 'calls' => $calls, 'rateUnknown' => $rateUnknown, 'scopeDenied' => false];
    }

    /**
     * Why a 200 events response cannot be used, or null if it looks fine.
     * $bodyKind is OsmApi::lastBodyKind(): an empty decoded reply is only accepted when the body
     * was a literal [] (an empty list). '' or {} are unusable, so a cached list is never wiped.
     * @param array<string, mixed> $res
     */
    public static function badResponseReason(array $res, ?string $bodyKind = null): ?string
    {
        if (array_key_exists('_raw', $res)) {
            return 'not JSON';
        }
        if (array_key_exists('error', $res) && $res['error'] !== null && $res['error'] !== false && $res['error'] !== '') {
            $e = $res['error'];
            $msg = is_array($e) ? ($e['message'] ?? null) : $e;
            return 'error' . (is_string($msg) && $msg !== '' ? ': ' . mb_substr($msg, 0, 120) : '');
        }
        if (array_key_exists('status', $res) && $res['status'] === false) {
            return 'status false';
        }
        if ($res === []) {
            return match ($bodyKind) {
                'empty-list' => null, // literal [] = no events
                'empty' => 'empty reply',
                'empty-object' => 'empty object',
                default => 'empty reply',
            };
        }
        $items = $res['items'] ?? ($res['data']['items'] ?? null);
        if (!is_array($items)) {
            return 'no event list';
        }
        return null;
    }

    /** Seconds until this section may be loaded again (0 = now). */
    public static function cooldownRemaining(string $sectionId, ?int $now = null): int
    {
        $now ??= time();
        $last = $_SESSION[self::REFRESH_KEY][$sectionId] ?? 0;
        $last = is_int($last) ? $last : 0;
        if ($last <= 0) {
            return 0;
        }
        return max(0, self::REFRESH_COOLDOWN_SEC - ($now - $last));
    }

    public static function markRefreshed(string $sectionId, ?int $now = null): void
    {
        if (!is_array($_SESSION[self::REFRESH_KEY] ?? null)) {
            $_SESSION[self::REFRESH_KEY] = [];
        }
        $_SESSION[self::REFRESH_KEY][$sectionId] = $now ?? time();
    }

    /** @param list<array{id:string,name:string,date:?string,archived:bool}> $events */
    public static function store(string $sectionId, string $sectionName, array $events, int $calls, ?int $now = null): void
    {
        $now ??= time();
        $cache = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
        unset($cache[$sectionId]);
        $cache[$sectionId] = [
            'builtAtTs' => $now,
            'builtAt' => (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/London'))->format('d/m/y H:i'),
            'sectionName' => $sectionName,
            'events' => $events,
            'calls' => $calls,
        ];
        while (count($cache) > self::MAX_CACHED_SECTIONS) {
            unset($cache[array_key_first($cache)]);
        }
        $_SESSION[self::SESSION_KEY] = $cache;
    }

    /** Cached list for a section, or null if absent/expired (expired ones are cleared). */
    public static function cached(string $sectionId, ?int $now = null): ?array
    {
        $now ??= time();
        $c = $_SESSION[self::SESSION_KEY][$sectionId] ?? null;
        if (!is_array($c) || !is_array($c['events'] ?? null)) {
            return null;
        }
        $ts = $c['builtAtTs'] ?? null;
        if (!is_int($ts) || $now - $ts >= self::CACHE_TTL_SEC || $ts > $now + 60) {
            unset($_SESSION[self::SESSION_KEY][$sectionId]);
            return null;
        }
        return $c;
    }

    /**
     * CSV text for the filtered rows. Cells starting with = + - @ (or tab / CR) get a leading quote
     * so spreadsheets do not run them as formulas.
     * @param list<array{id:string,name:string,date:?string,archived:bool}> $events
     */
    public static function csv(string $sectionName, array $events): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new \RuntimeException('Could not build CSV');
        }
        fputcsv($fh, ['Section', 'Start date', 'Event name', 'Archived'], ',', '"', '');
        foreach ($events as $e) {
            fputcsv($fh, array_map([self::class, 'csvSafe'], [
                $sectionName,
                self::ukDate($e['date']),
                $e['name'],
                $e['archived'] ? 'Yes' : 'No',
            ]), ',', '"', '');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . $out; // BOM so Excel reads UTF-8 names correctly
    }

    /** Leading quote for cells a spreadsheet could run as a formula, including after leading whitespace. */
    public static function csvSafe(string $v): string
    {
        if (preg_match('/^[\s\x{00A0}]*[=+\-@]/u', $v) || preg_match('/^[\t\r]/', $v)) {
            return "'" . $v;
        }
        return $v;
    }

    /** events-<section>-<all-time|yyyymmdd-yyyymmdd>.csv, letters/digits/hyphens only. */
    public static function csvFilename(string $sectionName, ?string $fromIso, ?string $toIso): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($sectionName)) ?? '', '-');
        $slug = substr($slug !== '' ? $slug : 'section', 0, 40);
        $slug = rtrim($slug, '-');
        if ($fromIso === null && $toIso === null) {
            $range = 'all-time';
        } else {
            $range = ($fromIso !== null ? str_replace('-', '', $fromIso) : 'start') . '-to-' . ($toIso !== null ? str_replace('-', '', $toIso) : 'end');
        }
        return 'events-' . $slug . '-' . $range . '.csv';
    }

    public static function humanWait(int $sec): string
    {
        if ($sec < 90) {
            return $sec . ' seconds';
        }
        return (int) ceil($sec / 60) . ' minutes';
    }
}
