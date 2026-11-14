<?php
declare(strict_types=1);
namespace App\GiftAid;
use App\Osm\OsmApi;
use App\Osm\OsmBlockedException;
use App\Osm\OsmErrorLog;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Loads Gift Aid grids into the session. load() is the only method that calls OSM.
 *
 * One GET per section term that overlaps the chosen dates:
 *   /ext/finances/giftaid/?action=getGrid&sectionid=&termid=&section=&no-total=1
 * The old giftaid.php endpoint is not used. Calls are paced, and the first error, 429, block,
 * unusable 200 or low allowance stops the run. Sections already finished in this run are returned
 * to store; a section that fails part-way is not stored, so an earlier good copy is left alone.
 * Nothing is written to disk.
 */
final class GiftAidLoader
{
    public const ENDPOINT = '/ext/finances/giftaid/';
    public const SESSION_KEY = 'giftAidCache';
    public const REFRESH_KEY = 'giftAidLastRefresh';
    public const REFRESH_COOLDOWN_SEC = 300;
    public const RATE_LOW_REMAINING = 60;
    public const CACHE_TTL_SEC = 7200;
    public const MAX_CACHED_SECTIONS = 12;
    private const CALL_DELAY_US = 300000;
    private const CALL_DELAY_UNKNOWN_US = 1500000;
    /** OAuth scope Gift Aid shares with Bank accounts. Do not add a new scope. */
    public const SCOPE = 'section:finance:read';
    public const SCOPE_MESSAGE = 'OSM hasn\'t given OSMHelper permission to read finance for this login. Sign out and sign in again, and allow Finance when OSM asks.';
    public const SCOPE_DENIED_KEY = 'giftAidScopeDenied';

    /**
     * Tests set this to 0 so a multi-term load does not sleep. null uses the real pause.
     * A missing X-RateLimit header still selects the longer pause when this is null.
     */
    public static ?int $pauseOverrideUs = null;

    /**
     * SCOPE_MESSAGE when this session cannot read finance, without calling OSM.
     * null means it is fine to try. Granted scopes win over the scopes requested at sign-in.
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
        return self::SCOPE_MESSAGE;
    }

    private static function hasScope(string $scopes): bool
    {
        $list = preg_split('/[\s,]+/', strtolower($scopes)) ?: [];
        return in_array(self::SCOPE, $list, true);
    }

    /** OSM text that means this login may not read finance (scope or role). */
    public static function looksLikeAccessRefusal(string $text): bool
    {
        $m = strtolower($text);
        return str_contains($m, 'access to this resource') || str_contains($m, 'permission') || str_contains($m, 'scope');
    }

    /**
     * Which term ids to fetch for one section.
     * No `terms` key (a sign-in from before they were stored) or an empty list: the current term only.
     * A term with no usable dates is included, because its grid might still hold donations in range.
     *
     * @param array<string, mixed> $section
     * @return array{termIds:list<string>,fallback:bool}
     */
    public static function plan(array $section, string $fromIso, string $toIso): array
    {
        $terms = $section['terms'] ?? null;
        if (!is_array($terms) || $terms === []) {
            return ['termIds' => [self::currentTermId($section)], 'fallback' => true];
        }
        $ids = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $id = trim((string) ($term['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $start = isset($term['start']) && is_string($term['start']) && $term['start'] !== '' ? $term['start'] : null;
            $end = isset($term['end']) && is_string($term['end']) && $term['end'] !== '' ? $term['end'] : null;
            if (!GiftAidGrid::termOverlaps($start, $end, $fromIso, $toIso)) {
                continue;
            }
            $ids[] = $id;
        }
        return ['termIds' => $ids, 'fallback' => false];
    }

    /** @param list<array<string, mixed>> $sections */
    public static function requestCount(array $sections, string $fromIso, string $toIso): int
    {
        $n = 0;
        foreach ($sections as $section) {
            $n += count(self::plan($section, $fromIso, $toIso)['termIds']);
        }
        return $n;
    }

    /**
     * @param list<array<string, mixed>> $sections login-list rows only
     * @return array{completed:list<array<string,mixed>>,stopReason:?string,scopeDenied:bool,calls:int,rateUnknown:bool,loaded:list<string>,skipped:list<string>}
     */
    public static function load(OsmApi $api, string $token, array $sections, string $fromIso, string $toIso): array
    {
        $completed = [];
        $loaded = [];
        $skipped = [];
        $stop = null;
        $scopeDenied = false;
        $calls = 0;
        $rateUnknown = false;
        foreach ($sections as $section) {
            $name = trim((string) ($section['section_name'] ?? ''));
            if ($name === '') {
                $name = (string) ($section['section_id'] ?? '');
            }
            if ($stop !== null) {
                $skipped[] = $name;
                continue;
            }
            $plan = self::plan($section, $fromIso, $toIso);
            if ($plan['termIds'] === []) {
                $completed[] = self::payload($section, $fromIso, $toIso, [], 0, false);
                $loaded[] = $name;
                continue;
            }
            if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
                $stop = 'Stopped to protect your OSM allowance (few requests left). Try again after it resets.';
                $skipped[] = $name;
                continue;
            }
            self::markRefreshed((string) $section['section_id']);
            $parsed = [];
            $sectionCalls = 0;
            $failed = false;
            foreach ($plan['termIds'] as $termId) {
                if ($sectionCalls > 0 && $api->isRateLow(self::RATE_LOW_REMAINING)) {
                    $stop = 'Stopped to protect your OSM allowance (few requests left). Try again after it resets.';
                    $failed = true;
                    break;
                }
                if ($calls > 0) {
                    self::pause($rateUnknown);
                }
                try {
                    $calls++;
                    $sectionCalls++;
                    $res = $api->get($token, self::ENDPOINT, [
                        'action' => 'getGrid',
                        'sectionid' => (string) $section['section_id'],
                        'termid' => $termId,
                        'section' => (string) ($section['section_type'] ?? ''),
                        'no-total' => '1',
                    ]);
                } catch (Throwable $e) {
                    $failed = true;
                    if ($e instanceof OsmBlockedException || str_starts_with($e->getMessage(), 'OSM_BLOCKED')) {
                        $stop = 'OSM has blocked requests from OSMHelper, so it stopped straight away. Do not retry; contact OSM support.';
                    } elseif ($e->getCode() === 429) {
                        $ra = $api->getRetryAfterSeconds();
                        $stop = 'OSM said too many requests, so OSMHelper stopped. '
                            . ($ra !== null ? 'Try again in about ' . self::humanWait($ra) . '.' : 'Wait for your allowance to reset.');
                    } elseif (in_array($e->getCode(), [401, 403], true) || self::looksLikeAccessRefusal($e->getMessage())) {
                        if ($e->getCode() !== 401) {
                            $_SESSION[self::SCOPE_DENIED_KEY] = true;
                        }
                        $scopeDenied = true;
                        $stop = self::SCOPE_MESSAGE;
                    } else {
                        $stop = 'OSM returned an error for ' . $name . ', so OSMHelper stopped without trying again.';
                    }
                    break;
                }
                if (!$api->lastResponseHadRateHeaders()) {
                    $rateUnknown = true;
                }
                $bad = GiftAidGrid::badResponseReason($res, $api->lastBodyKind());
                if ($bad !== null) {
                    self::logBadReply((string) $section['section_id'], $bad);
                    if (GiftAidGrid::reasonCode($bad) === 'osm-error' && self::looksLikeAccessRefusal($bad)) {
                        $_SESSION[self::SCOPE_DENIED_KEY] = true;
                        $scopeDenied = true;
                        $stop = self::SCOPE_MESSAGE;
                    } else {
                        $stop = 'OSM sent an unexpected reply for ' . $name . ' (' . GiftAidGrid::reasonCode($bad) . '), so OSMHelper stopped without trying again.';
                    }
                    $failed = true;
                    break;
                }
                foreach (GiftAidGrid::parseGrid($res) as $row) {
                    $parsed[] = $row;
                }
            }
            if ($failed) {
                $skipped[] = $name;
                continue;
            }
            $completed[] = self::payload($section, $fromIso, $toIso, self::withSection(GiftAidGrid::mergePeople($parsed), $section), $sectionCalls, $plan['fallback']);
            $loaded[] = $name;
        }
        return [
            'completed' => $completed,
            'stopReason' => $stop,
            'scopeDenied' => $scopeDenied,
            'calls' => $calls,
            'rateUnknown' => $rateUnknown,
            'loaded' => $loaded,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param list<array<string, mixed>> $people
     * @param array<string, mixed> $section
     * @return list<array<string, mixed>>
     */
    private static function withSection(array $people, array $section): array
    {
        $out = [];
        foreach ($people as $person) {
            $person['group'] = trim((string) ($section['group_name'] ?? ''));
            $person['section'] = trim((string) ($section['section_name'] ?? ''));
            $person['sectionId'] = (string) ($section['section_id'] ?? '');
            $out[] = $person;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $section
     * @param list<array<string, mixed>> $declarations
     * @return array<string, mixed>
     */
    private static function payload(array $section, string $from, string $to, array $declarations, int $calls, bool $fallback): array
    {
        return [
            'sectionId' => (string) ($section['section_id'] ?? ''),
            'sectionName' => trim((string) ($section['section_name'] ?? '')),
            'sectionType' => (string) ($section['section_type'] ?? ''),
            'groupName' => trim((string) ($section['group_name'] ?? '')),
            'loadedFrom' => $from,
            'loadedTo' => $to,
            'termsFallback' => $fallback,
            'calls' => $calls,
            'declarations' => $declarations,
        ];
    }

    /** @param array<string, mixed> $section */
    private static function currentTermId(array $section): string
    {
        $id = trim((string) ($section['current_term_id'] ?? ''));
        return $id !== '' ? $id : '-1';
    }

    private static function pause(bool $rateUnknown): void
    {
        $us = self::$pauseOverrideUs;
        if ($us === null) {
            $us = $rateUnknown ? self::CALL_DELAY_UNKNOWN_US : self::CALL_DELAY_US;
        }
        if ($us > 0) {
            usleep($us);
        }
    }

    /**
     * Reason code and section id only. The OSM text inside a badResponseReason() string is not logged.
     */
    private static function logBadReply(string $sectionId, string $reason): void
    {
        $code = GiftAidGrid::reasonCode($reason);
        $sid = ctype_digit($sectionId) ? $sectionId : '?';
        error_log('OSMHelper Gift Aid: unusable 200 from ' . self::ENDPOINT . ' (section ' . $sid . '): ' . $code . '. Stopped, no retry.');
        try {
            OsmErrorLog::log([
                'method' => 'GET',
                'endpoint' => self::ENDPOINT,
                'action' => 'getGrid',
                'http_status' => 200,
                'osm_code' => $code,
                'kind' => 'bad-200',
                'section_id' => $sid,
                'badge_id' => null,
                'detail' => 'gift aid grid',
            ]);
        } catch (Throwable) {
        }
    }

    public static function cooldownRemaining(string $sectionId, ?int $now = null): int
    {
        $now ??= time();
        $last = $_SESSION[self::REFRESH_KEY][$sectionId] ?? 0;
        $last = is_int($last) ? $last : (is_numeric($last) ? (int) $last : 0);
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

    /** @param array<string, mixed> $payload */
    public static function store(array $payload, ?int $now = null): void
    {
        $now ??= time();
        $id = (string) ($payload['sectionId'] ?? '');
        if ($id === '') {
            return;
        }
        $cache = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
        unset($cache[$id]);
        $payload['builtAtTs'] = $now;
        $payload['builtAt'] = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/London'))->format('d/m/y H:i');
        $cache[$id] = $payload;
        while (count($cache) > self::MAX_CACHED_SECTIONS) {
            unset($cache[array_key_first($cache)]);
        }
        $_SESSION[self::SESSION_KEY] = $cache;
    }

    /** @return array<string, mixed>|null */
    public static function cached(string $sectionId, ?int $now = null): ?array
    {
        $now ??= time();
        $c = $_SESSION[self::SESSION_KEY][$sectionId] ?? null;
        if (!is_array($c) || !is_array($c['declarations'] ?? null)) {
            return null;
        }
        $ts = $c['builtAtTs'] ?? null;
        if (!is_int($ts) || $now - $ts >= self::CACHE_TTL_SEC || $ts > $now + 60) {
            unset($_SESSION[self::SESSION_KEY][$sectionId]);
            return null;
        }
        return $c;
    }

    /** True when this copy was loaded for a range that includes $from..$to. */
    public static function covers(array $cache, string $fromIso, string $toIso): bool
    {
        $from = $cache['loadedFrom'] ?? '';
        $to = $cache['loadedTo'] ?? '';
        return is_string($from) && is_string($to) && $from !== '' && $to !== '' && $from <= $fromIso && $to >= $toIso;
    }

    public static function humanWait(int $sec): string
    {
        if ($sec < 90) {
            return $sec . ' seconds';
        }
        return (int) ceil($sec / 60) . ' minutes';
    }
}
