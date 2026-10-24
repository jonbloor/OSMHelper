<?php
declare(strict_types=1);
namespace App\Members;

use App\Osm\LoginSections;
use App\Osm\OsmApi;
use App\Osm\OsmBusyException;
use App\Osm\OsmCache;
use App\Osm\OsmLists;
use App\Osm\OsmRateGuard;
use Throwable;

/**
 * Young Leaders: which other sections each member of one section helps in.
 *
 * OSM budget for one section: 1 getListOfMembers + 1 getIndividual per member (1 + N), one call per
 * step() so the browser can show progress. Every call is paced (PAUSE_US, longer when OSM sends no
 * rate headers), and loading stops, keeping what has loaded, when the allowance is low
 * (RATE_LOW_REMAINING), at the first 429 or at the first error. Results are cached (cleared at sign-out) for up to 24 hours per
 * section and per member (OsmCache KIND_TTLS), so filters, CSV and repeat views make no calls.
 *
 * Privacy: only first name, last name, age and the "Group: Section" names OSM lists in `others` are
 * kept, shown, exported or cached. slimMember()/slimListMember() whitelist those fields, so contact,
 * medical and date-of-birth data in OSM's replies is dropped before anything is stored.
 */
final class YoungLeaders
{
    public const LIST_KIND = 'ylsection';
    public const MEMBER_KIND = 'ylmember';

    /** Stop before a call when OSM says this many requests or fewer are left (as Find a member). */
    public const RATE_LOW_REMAINING = 60;
    /** Pause before each call (microseconds). */
    public const PAUSE_US = 1_000_000;
    /** Pause when OSM hasn't told us the allowance yet (no rate headers seen). */
    public const PAUSE_UNKNOWN_US = 2_000_000;
    /** Refuse sections bigger than this: they would spend too much of the allowance in one go. */
    public const MAX_MEMBERS = 150;
    /** Browser asks "are you sure?" before loading more than this many members. */
    public const CONFIRM_ABOVE = 40;
    /** Longest list of other sections kept per member, and longest name kept. */
    private const MAX_OTHERS = 60;
    private const MAX_TEXT = 160;

    public const STOP_RATE_LOW = 'Stopped to protect your OSM allowance (few requests left). What has loaded is kept. Try again after it resets.';

    /** Section name looks like a Young Leaders unit ("Young Leader…" or "YLU", any case). */
    public static function isYoungLeaderSectionName(string $name): bool
    {
        return stripos($name, 'young leader') !== false || stripos($name, 'ylu') !== false;
    }

    /**
     * Sections from the sign-in list (no OSM calls), Young Leaders units first, then the usual order.
     * Unknown-type sections are included only when their name says Young Leaders.
     *
     * @param list<array<string, mixed>> $sessionSections LoginSections::fromSession()/ensure()
     * @return list<array{id:string,name:string,type:string,typeLabel:string,groupName:string,termId:mixed,isYoungLeaders:bool}>
     */
    public static function sectionChoices(array $sessionSections): array
    {
        $choices = LoginSections::memberListChoices($sessionSections);
        $seen = array_fill_keys(array_column($choices, 'id'), true);
        foreach ($sessionSections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $id = (string) ($sec['section_id'] ?? $sec['id'] ?? '');
            $name = trim((string) ($sec['section_name'] ?? $sec['name'] ?? ''));
            $type = strtolower((string) ($sec['section_type'] ?? 'unknown'));
            if ($id === '' || isset($seen[$id]) || $type === 'waiting' || !self::isYoungLeaderSectionName($name)) {
                continue;
            }
            $seen[$id] = true;
            $choices[] = [
                'id' => $id,
                'name' => $name,
                'type' => $type === '' ? 'unknown' : $type,
                'typeLabel' => 'Section',
                'groupName' => trim((string) ($sec['group_name'] ?? '')),
                'termId' => $sec['current_term_id'] ?? -1,
            ];
        }
        $yl = [];
        $rest = [];
        foreach ($choices as $c) {
            $c['isYoungLeaders'] = self::isYoungLeaderSectionName($c['name']);
            if ($c['isYoungLeaders']) {
                $yl[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        return array_merge($yl, $rest);
    }

    /** @param list<array<string, mixed>> $choices @return array<string, mixed>|null */
    public static function findChoice(array $choices, string $id): ?array
    {
        foreach ($choices as $c) {
            if ($id !== '' && (string) $c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Age as [label, months] from OSM text: "15 years and 9 months" (getIndividual) or "15 / 09"
     * (getListOfMembers). Unknown → ['', -1].
     *
     * @return array{0:string,1:int}
     */
    public static function ageInfo(mixed $age): array
    {
        $t = is_scalar($age) ? trim((string) $age) : '';
        if (preg_match('/^(\d{1,3})\s*years?(?:\s*(?:and|,)?\s*(\d{1,2})\s*months?)?/i', $t, $m)
            || preg_match('#^(\d{1,3})\s*/\s*(\d{1,2})$#', $t, $m)) {
            $y = (int) $m[1];
            $mo = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            return [$y . 'y ' . $mo . 'm', $y * 12 + $mo];
        }
        return ['', -1];
    }

    private static function text(mixed $v): string
    {
        $s = is_scalar($v) ? trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '') : '';
        return mb_substr($s, 0, self::MAX_TEXT);
    }

    /**
     * One getListOfMembers row → {scoutid, firstname, lastname, age, ageMonths}, or null for the
     * Leaders patrol (adults; not loaded) or a row without an id. Nothing else is kept.
     *
     * @param array<string, mixed> $raw
     * @return array{scoutid:string,firstname:string,lastname:string,age:string,ageMonths:int}|null
     */
    public static function slimListMember(array $raw): ?array
    {
        $id = self::text($raw['scoutid'] ?? $raw['member_id'] ?? '');
        if ($id === '' || !preg_match('/^\d+$/', $id)) {
            return null;
        }
        if (Patrols::isLeader(self::text($raw['patrol'] ?? ''), isset($raw['patrolid']) && is_scalar($raw['patrolid']) ? (string) $raw['patrolid'] : null)) {
            return null;
        }
        [$age, $months] = self::ageInfo($raw['age'] ?? '');
        return [
            'scoutid' => $id,
            'firstname' => self::text($raw['firstname'] ?? $raw['first_name'] ?? ''),
            'lastname' => self::text($raw['lastname'] ?? $raw['last_name'] ?? ''),
            'age' => $age,
            'ageMonths' => $months,
        ];
    }

    /** @param array<string, mixed> $res @return list<array{scoutid:string,firstname:string,lastname:string,age:string,ageMonths:int}> */
    public static function slimList(array $res): array
    {
        $out = [];
        foreach (OsmLists::items($res) as $raw) {
            $m = is_array($raw) ? self::slimListMember($raw) : null;
            if ($m !== null && !isset($out[$m['scoutid']])) {
                $out[$m['scoutid']] = $m;
            }
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => [strtolower($a['lastname']), strtolower($a['firstname'])] <=> [strtolower($b['lastname']), strtolower($b['firstname'])]);
        return $out;
    }

    /** @param array<string, mixed> $res @return array<string, mixed> */
    public static function individualPayload(array $res): array
    {
        $d = $res['data'] ?? null;
        if (is_array($d)) {
            return is_array($d['data'] ?? null) ? $d['data'] : $d;
        }
        return [];
    }

    /** Why a reply can't be used (null = fine). Same checks as Find a member. @param array<string, mixed> $res */
    public static function badResponseReason(array $res): ?string
    {
        return ContactIndex::badResponseReason($res);
    }

    /**
     * getIndividual reply → the only fields kept: names, age and the other sections' names.
     * The section being viewed ("Group: Section") is left out of `others`.
     *
     * @param array<string, mixed> $res
     * @param array{scoutid:string,firstname:string,lastname:string,age:string,ageMonths:int} $listRow
     * @return array{firstname:string,lastname:string,age:string,ageMonths:int,others:list<string>}
     */
    public static function slimMember(array $res, array $listRow, string $ownSectionLabel = ''): array
    {
        $d = self::individualPayload($res);
        [$age, $months] = self::ageInfo($d['age'] ?? '');
        if ($months < 0) {
            $age = $listRow['age'];
            $months = $listRow['ageMonths'];
        }
        $others = [];
        $own = strtolower(self::text($ownSectionLabel));
        foreach (is_array($d['others'] ?? null) ? $d['others'] : [] as $o) {
            $s = self::text($o);
            if ($s === '' || ($own !== '' && strtolower($s) === $own) || in_array($s, $others, true)) {
                continue;
            }
            $others[] = $s;
            if (count($others) >= self::MAX_OTHERS) {
                break;
            }
        }
        natcasesort($others);
        $first = self::text($d['firstname'] ?? '');
        $last = self::text($d['lastname'] ?? '');
        return [
            'firstname' => $first !== '' ? $first : $listRow['firstname'],
            'lastname' => $last !== '' ? $last : $listRow['lastname'],
            'age' => $age,
            'ageMonths' => $months,
            'others' => array_values($others),
        ];
    }

    /** "Group: Section" → [group, section]; no colon → ['', whole]. @return array{0:string,1:string} */
    public static function splitOther(string $other): array
    {
        $p = strpos($other, ': ');
        return $p === false ? ['', $other] : [substr($other, 0, $p), substr($other, $p + 2)];
    }

    public static function memberScope(string $sectionId, string $scoutId): string
    {
        return $sectionId . ':' . $scoutId;
    }

    /**
     * Cached view of a section (0 OSM calls): one row per member on the cached list, with their
     * cached other sections when loaded. pending = members still to load (missing, or older than
     * $refreshSince after Refresh).
     *
     * tooLarge = member count when OSM's list was over MAX_MEMBERS (kept, so pressing Load again
     * doesn't spend the list call again until Refresh or the 24 hours are up).
     *
     * @return array{listCached:bool,listStale:bool,tooLarge:?int,rows:list<array<string, mixed>>,total:int,loaded:int,pending:int,options:list<array{name:string,count:int}>,fetchedAt:?int}
     */
    public static function view(string $userId, string $sectionId, ?float $refreshSince = null): array
    {
        $list = OsmCache::get(self::LIST_KIND, $userId, $sectionId);
        $members = is_array($list['members'] ?? null) ? $list['members'] : [];
        $rows = [];
        $loaded = 0;
        $pending = 0;
        $counts = [];
        $oldest = null;
        foreach ($members as $m) {
            if (!is_array($m) || !isset($m['scoutid'])) {
                continue;
            }
            $c = OsmCache::get(self::MEMBER_KIND, $userId, self::memberScope($sectionId, (string) $m['scoutid']));
            $isLoaded = is_array($c) && is_array($c['others'] ?? null);
            $fresh = $isLoaded && ($refreshSince === null || self::fetchedAtOf($c) >= $refreshSince);
            $src = $isLoaded ? $c : $m;
            $others = $isLoaded ? array_values(array_filter($c['others'], 'is_string')) : [];
            if ($isLoaded) {
                $loaded++;
                $at = (int) ($c['fetchedAt'] ?? 0);
                $oldest = $oldest === null ? $at : min($oldest, $at);
                foreach ($others as $o) {
                    $counts[$o] = ($counts[$o] ?? 0) + 1;
                }
            }
            if (!$fresh) {
                $pending++;
            }
            $rows[] = [
                'scoutid' => (string) $m['scoutid'],
                'firstname' => (string) ($src['firstname'] ?? ''),
                'lastname' => (string) ($src['lastname'] ?? ''),
                'age' => (string) ($src['age'] ?? ''),
                'ageMonths' => (int) ($src['ageMonths'] ?? -1),
                'loaded' => $isLoaded,
                'others' => array_map(static fn (string $o): array => ['full' => $o, 'group' => self::splitOther($o)[0], 'section' => self::splitOther($o)[1]], $others),
            ];
        }
        uksort($counts, 'strnatcasecmp');
        $options = [];
        foreach ($counts as $name => $n) {
            $options[] = ['name' => (string) $name, 'count' => $n];
        }
        $listAt = is_array($list) ? (int) ($list['fetchedAt'] ?? 0) : null;
        return [
            'listCached' => $list !== null,
            'tooLarge' => self::tooLargeCount($userId, $sectionId, $refreshSince),
            'listStale' => $list !== null && $refreshSince !== null && self::fetchedAtOf($list) < $refreshSince,
            'rows' => $rows,
            'total' => count($rows),
            'loaded' => $loaded,
            'pending' => $list === null ? 0 : $pending,
            'options' => $options,
            'fetchedAt' => $oldest ?? $listAt,
        ];
    }

    /**
     * When a cached entry was fetched, to the microsecond (fetchedAtF), so a Refresh pressed in the
     * same second as a fetch still counts that entry as older. Entries from before this have only
     * whole seconds (fetchedAt).
     * @param array<string, mixed> $entry
     */
    public static function fetchedAtOf(array $entry): float
    {
        $f = $entry['fetchedAtF'] ?? null;
        return is_float($f) || is_int($f) ? (float) $f : (float) (int) ($entry['fetchedAt'] ?? 0);
    }

    /**
     * Why loading can't start right now (this user's shared OSM backoff, or an app-wide block),
     * or null when it can. Reads the saved backoff state only: no OSM call.
     */
    public static function busyMessage(?string $userId): ?string
    {
        try {
            OsmRateGuard::forUser($userId)->assertCanCall(false);
            return null;
        } catch (OsmBusyException $e) {
            return $e->getMessage();
        }
    }

    /** Count of a section found too large, unless a Refresh since then asks to check again. */
    private static function tooLargeCount(string $userId, string $sectionId, ?float $refreshSince): ?int
    {
        $t = YoungLeaderLimits::tooLarge($userId, $sectionId);
        if ($t === null || ($refreshSince !== null && $t['at'] < $refreshSince)) {
            return null;
        }
        return $t['count'];
    }

    public static function tooLargeMessage(string $sectionName, int $count): string
    {
        return $sectionName . ' has ' . $count . ' members (more than ' . self::MAX_MEMBERS . '). Loading each one would use too much of your OSM allowance, so this page only works for smaller sections.';
    }

    /** Pause before the next call, given when this user's previous Young Leaders call went out. */
    public static function pauseUs(?float $lastCallAt, float $now, bool $rateKnown): int
    {
        $want = $rateKnown ? self::PAUSE_US : self::PAUSE_UNKNOWN_US;
        if ($lastCallAt === null || $lastCallAt > $now) {
            return $lastCallAt === null ? 0 : $want;
        }
        return max(0, $want - (int) round(($now - $lastCallAt) * 1_000_000));
    }

    /**
     * Load the next piece for a section: the member list, else the next member not yet loaded.
     * At most ONE OSM call. Never retries.
     *
     * @param array{id:string,name:string,type:string,groupName:string,termId:mixed} $section
     * @param array{lastCallAt:?float,refreshSince:?float,sleep:callable(int):void,now:callable():float,confirmed?:bool} $ctx
     * @return array{state:string,total:int,loaded:int,calls:int,message:?string,lastCallAt:?float,confirm:bool}
     */
    public static function step(OsmApi $api, string $token, array $section, string $userId, array $ctx): array
    {
        $sectionId = (string) $section['id'];
        $refreshSince = $ctx['refreshSince'];
        $view = self::view($userId, $sectionId, $refreshSince);
        // loaded = members whose cached entry is current (Refresh makes older ones count as pending).
        $out = ['state' => 'running', 'total' => $view['total'], 'loaded' => $view['total'] - $view['pending'], 'calls' => 0, 'message' => null, 'lastCallAt' => $ctx['lastCallAt'], 'confirm' => false];

        $needList = !$view['listCached'] || $view['listStale'];
        if ($view['tooLarge'] !== null) {
            return ['state' => 'stopped', 'total' => $view['tooLarge'], 'message' => self::tooLargeMessage($section['name'], $view['tooLarge'])] + $out;
        }
        $next = null;
        if (!$needList) {
            foreach ($view['rows'] as $r) {
                $c = OsmCache::get(self::MEMBER_KIND, $userId, self::memberScope($sectionId, $r['scoutid']));
                if (!is_array($c) || ($refreshSince !== null && self::fetchedAtOf($c) < $refreshSince)) {
                    $next = $r;
                    break;
                }
            }
            if ($next === null) {
                $out['state'] = 'done';
                return $out;
            }
            // More than CONFIRM_ABOVE members still to load: the user must have agreed (the page
            // asks, then sends confirmed=1). Checked here too, not only in the browser. No call.
            if ($view['pending'] > self::CONFIRM_ABOVE && !($ctx['confirmed'] ?? false)) {
                return ['state' => 'confirm', 'confirm' => true, 'message' => $view['pending'] . ' members still to load. That uses ' . $view['pending'] . ' OSM requests, so OSMHelper needs you to confirm first.'] + $out;
            }
        }
        if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
            return ['state' => 'stopped', 'message' => self::STOP_RATE_LOW] + $out;
        }
        $snap = OsmApi::sessionSnapshot();
        $rateKnown = is_array($snap) && ($snap['remaining'] ?? null) !== null;
        $wait = self::pauseUs($ctx['lastCallAt'], ($ctx['now'])(), $rateKnown);
        if ($wait > 0) {
            ($ctx['sleep'])($wait);
        }
        $what = $needList ? 'the member list' : trim($next['firstname'] . ' ' . $next['lastname']);
        $started = microtime(true);
        $sentBefore = $api->requestsSent();
        try {
            $out['lastCallAt'] = ($ctx['now'])();
            if ($needList) {
                $res = $api->get($token, '/ext/members/contact/', [
                    'action' => 'getListOfMembers',
                    'sectionid' => $sectionId,
                    'termid' => (string) ($section['termId'] ?? -1),
                    'section' => (string) $section['type'],
                    'sort' => 'patrol',
                ]);
            } else {
                $res = $api->get($token, '/ext/members/contact/', [
                    'action' => 'getIndividual',
                    'sectionid' => $sectionId,
                    'scoutid' => $next['scoutid'],
                    'termid' => (string) ($section['termId'] ?? -1),
                    'context' => 'members',
                ]);
            }
        } catch (OsmBusyException $e) {
            // A 429 reply or a refusal by the shared guard (no request sent): calls says which.
            $out['calls'] = $api->requestsSent() - $sentBefore;
            return ['state' => 'stopped', 'message' => $e->getMessage() . ' What has loaded is kept.'] + $out;
        } catch (Throwable $e) {
            $out['calls'] = $api->requestsSent() - $sentBefore;
            return ['state' => 'stopped', 'message' => 'OSM returned an error for ' . $what . ', so OSMHelper stopped without trying again. What has loaded is kept.'] + $out;
        }
        $out['calls'] = $api->requestsSent() - $sentBefore;

        $nowF = OsmCache::currentMicrotime();
        $now = (int) $nowF;
        if ($needList) {
            if (!is_array($res) || (self::badResponseReason($res) !== null && OsmLists::items($res) === [])) {
                return ['state' => 'stopped', 'message' => 'OSM did not return a member list for ' . $section['name'] . ', so OSMHelper stopped without trying again.'] + $out;
            }
            $members = self::slimList($res);
            if (count($members) > self::MAX_MEMBERS) {
                // Remember only the count (no names), per OSM user, so the next press (even after
                // signing in again) doesn't spend the list call again until Refresh or 24 hours.
                YoungLeaderLimits::setTooLarge($userId, $sectionId, count($members), $nowF);
                return ['state' => 'stopped', 'total' => count($members), 'message' => self::tooLargeMessage($section['name'], count($members))] + $out;
            }
            OsmCache::put(self::LIST_KIND, $userId, $sectionId, ['members' => $members, 'fetchedAt' => $now, 'fetchedAtF' => $nowF], $started);
            YoungLeaderLimits::setTooLarge($userId, $sectionId, null, $nowF);
            $after = self::view($userId, $sectionId, $refreshSince);
            $out['total'] = $after['total'];
            $out['loaded'] = $after['total'] - $after['pending'];
            $out['confirm'] = $after['pending'] > self::CONFIRM_ABOVE;
            if ($after['pending'] === 0) {
                $out['state'] = 'done';
            }
            return $out;
        }

        if (!is_array($res) || self::badResponseReason($res) !== null) {
            return ['state' => 'stopped', 'message' => 'OSM returned an unexpected reply for ' . $what . ', so OSMHelper stopped without trying again. What has loaded is kept.'] + $out;
        }
        $label = trim($section['groupName']) !== '' ? $section['groupName'] . ': ' . $section['name'] : '';
        $slim = self::slimMember($res, $next, $label);
        $slim['fetchedAt'] = $now;
        $slim['fetchedAtF'] = $nowF;
        OsmCache::put(self::MEMBER_KIND, $userId, self::memberScope($sectionId, $next['scoutid']), $slim, $started);
        $out['loaded']++;
        if ($out['loaded'] >= $out['total']) {
            $out['state'] = 'done';
        }
        return $out;
    }
}
