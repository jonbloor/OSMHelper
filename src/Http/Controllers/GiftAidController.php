<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\GiftAid\GiftAidGrid;
use App\GiftAid\GiftAidLoader;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\LoginSections;
use App\Osm\OsmApi;

/**
 * Gift Aid CSV export. GET /gift-aid and the two CSV routes only read the session copy.
 * POST /gift-aid/load is the only OSM call (one getGrid per overlapping term).
 */
final class GiftAidController
{
    public const TERMS_NOTE = 'Sign out and in again to use date ranges across terms. This sign-in was saved before term dates were kept, so Gift Aid can only request each section’s current term.';

    public function __construct(private ?OsmApi $api = null)
    {
    }

    private function api(): OsmApi
    {
        return $this->api ?? new OsmApi();
    }

    public function index(): void
    {
        Auth::requireLogin();
        $data = self::viewData($_GET);
        $flash = $_SESSION['giftAidFlash'] ?? null;
        unset($_SESSION['giftAidFlash']);
        $data['flash'] = is_array($flash) ? $flash : null;
        App::render('gift-aid.twig', Auth::baseContext($data));
    }

    public function load(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $out = self::handleLoad($token, $_POST, $this->api());
        $_SESSION['giftAidFlash'] = $out['flash'];
        header('Location: /gift-aid/' . ($out['query'] !== [] ? '?' . http_build_query($out['query']) : ''));
        exit;
    }

    /** Declarations register. Never calls OSM. */
    public function declarationsCsv(): void
    {
        $this->sendExport('declarations');
    }

    /** HMRC schedule rows. Never calls OSM. */
    public function hmrcCsv(): void
    {
        $this->sendExport('hmrc');
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function viewData(array $query): array
    {
        [$choices, $sectionError] = self::choices();
        [$from, $to, $rangeError, $swapped] = self::range($query, true);
        $charity = self::charityFrom($query);
        $selectedIds = self::selectedIds($choices, $query);
        $picked = LoginSections::pickedSections($choices, $selectedIds);
        $termsNote = false;
        $emptyTerms = false;
        $fallback = false;
        if ($from !== null && $to !== null) {
            foreach ($picked as $section) {
                if (!array_key_exists('terms', $section) || $section['terms'] === null) {
                    $termsNote = true;
                } elseif ($section['terms'] === []) {
                    $emptyTerms = true;
                }
                if (GiftAidLoader::plan($section, $from, $to)['fallback']) {
                    $fallback = true;
                }
            }
        }
        $requestCount = ($from !== null && $to !== null) ? GiftAidLoader::requestCount($picked, $from, $to) : 0;
        $people = [];
        $missing = [];
        $loadedBits = [];
        if ($from !== null && $to !== null) {
            foreach ($picked as $section) {
                $cache = GiftAidLoader::cached((string) $section['section_id']);
                if ($cache === null || !GiftAidLoader::covers($cache, $from, $to)) {
                    $missing[] = (string) $section['section_name'];
                    continue;
                }
                foreach ($cache['declarations'] as $row) {
                    if (is_array($row)) {
                        $people[] = $row;
                    }
                }
                $loadedBits[] = (string) $section['section_name'] . ' (' . (string) ($cache['builtAt'] ?? '') . ')';
            }
        }
        $preview = null;
        $covered = count($picked) - count($missing);
        if ($rangeError === null && $from !== null && $to !== null && $picked !== [] && $covered > 0) {
            $preview = GiftAidGrid::report($people, $from, $to, $charity)['preview'];
        }
        $wait = 0;
        if ($from !== null && $to !== null) {
            foreach ($picked as $section) {
                if (GiftAidLoader::plan($section, $from, $to)['termIds'] === []) {
                    continue;
                }
                $wait = max($wait, GiftAidLoader::cooldownRemaining((string) $section['section_id']));
            }
        }
        $fromUk = GiftAidGrid::ukDate($from);
        $toUk = GiftAidGrid::ukDate($to);
        $groups = LoginSections::groupChoices($choices);
        return [
            'title' => 'Gift Aid',
            'groupName' => (string) ($_SESSION['groupName'] ?? 'OSM Helper'),
            'groups' => $groups,
            'multiGroup' => count($groups) > 1,
            'choiceCount' => count($choices),
            'sectionError' => $sectionError,
            'selectedIds' => $selectedIds,
            'charity' => $charity,
            'defaultCharity' => GiftAidGrid::DEFAULT_CLAIM_CHARITY,
            'from' => $fromUk,
            'to' => $toUk,
            'fromRaw' => $rangeError !== null && is_string($query['from'] ?? null) ? mb_substr($query['from'], 0, 20) : $fromUk,
            'toRaw' => $rangeError !== null && is_string($query['to'] ?? null) ? mb_substr($query['to'], 0, 20) : $toUk,
            'rangeError' => $rangeError,
            'rangeNote' => $swapped ? 'The From date was after the To date, so they were swapped: showing ' . $fromUk . ' to ' . $toUk . '.' : null,
            'requestCount' => $requestCount,
            'requestNote' => self::requestNote($requestCount, $fallback),
            'termsNote' => $termsNote ? self::TERMS_NOTE : null,
            'emptyTermsNote' => $emptyTerms ? 'One or more sections have no term dates on this sign-in, so only the current term will be loaded for those.' : null,
            'preview' => $preview,
            'missingNote' => $missing !== [] && $preview !== null ? 'Not in this preview or the CSV files yet: ' . self::nameList($missing) . '. Load Gift Aid to include them.' : null,
            'loadedNote' => $loadedBits !== [] ? 'Loaded ' . self::nameList($loadedBits) . '. This copy is cleared about 2 hours after loading, or when you sign out.' : null,
            'nothingLoaded' => $preview === null && $rangeError === null && $selectedIds !== [] && $sectionError === null,
            'csvQuery' => ($from !== null && $to !== null) ? http_build_query([
                'from' => $fromUk,
                'to' => $toUk,
                'charity' => $charity,
                'show' => '1',
                'section_ids' => $selectedIds,
            ]) : '',
            'cooldownMinutes' => (int) (GiftAidLoader::REFRESH_COOLDOWN_SEC / 60),
            'cooldownWait' => $wait > 0 ? (int) ceil($wait / 60) : 0,
            'scopeHint' => GiftAidLoader::scopeHint(),
            'loadSections' => $picked,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{flash:array{kind:string,message:string,scope?:bool},query:array<string,mixed>}
     */
    public static function handleLoad(string $token, array $post, OsmApi $api): array
    {
        [$from, $to, $rangeError] = self::range($post, true);
        $charity = self::charityFrom($post);
        [$choices, $sectionError] = self::choices();
        $raw = $post['section_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        $picked = $sectionError === null ? LoginSections::pickedSections($choices, $raw) : [];
        $ids = array_column($picked, 'section_id');
        $query = ($from !== null && $to !== null) ? self::redirectQuery($from, $to, $charity, $ids) : self::rawQuery($post, $charity, $ids);
        if (GiftAidLoader::scopeHint() !== null) {
            return ['flash' => ['kind' => 'error', 'message' => GiftAidLoader::SCOPE_MESSAGE, 'scope' => true], 'query' => $query];
        }
        if ($sectionError !== null) {
            return ['flash' => ['kind' => 'error', 'message' => $sectionError], 'query' => $query];
        }
        if ($rangeError !== null || $from === null || $to === null) {
            return ['flash' => ['kind' => 'error', 'message' => $rangeError ?? 'Enter both dates as dd/mm/yyyy, for example 06/04/2026.'], 'query' => $query];
        }
        if ($picked === []) {
            return ['flash' => ['kind' => 'error', 'message' => 'Tick at least one section, then load Gift Aid.'], 'query' => $query];
        }
        $cooling = [];
        $maxWait = 0;
        foreach ($picked as $section) {
            if (GiftAidLoader::plan($section, $from, $to)['termIds'] === []) {
                continue;
            }
            $wait = GiftAidLoader::cooldownRemaining((string) $section['section_id']);
            if ($wait > 0) {
                $cooling[] = (string) $section['section_name'];
                $maxWait = max($maxWait, $wait);
            }
        }
        if ($cooling !== []) {
            return ['flash' => ['kind' => 'error', 'message' => 'Gift Aid for ' . self::nameList($cooling) . ' was loaded a moment ago. To protect your OSM allowance you can load again in ' . (int) ceil($maxWait / 60) . ' min.'], 'query' => $query];
        }
        $kept = false;
        foreach ($picked as $section) {
            if (GiftAidLoader::cached((string) $section['section_id']) !== null) {
                $kept = true;
                break;
            }
        }
        $result = GiftAidLoader::load($api, $token, $picked, $from, $to);
        foreach ($result['completed'] as $payload) {
            GiftAidLoader::store($payload);
        }
        $note = !empty($result['rateUnknown']) ? ' OSM did not report your remaining allowance, so OSMHelper paused longer between requests.' : '';
        if (!empty($result['scopeDenied'])) {
            $msg = GiftAidLoader::SCOPE_MESSAGE;
            if ($result['loaded'] !== []) {
                $msg .= ' Loaded: ' . self::nameList($result['loaded']) . '.';
            } elseif ($kept) {
                $msg .= ' Your earlier copy was kept.';
            }
            return ['flash' => ['kind' => 'error', 'message' => $msg, 'scope' => true], 'query' => $query];
        }
        if ($result['stopReason'] !== null) {
            $msg = (string) $result['stopReason'];
            if ($result['loaded'] !== []) {
                $msg .= ' Loaded: ' . self::nameList($result['loaded']) . '.';
            } elseif ($kept) {
                $msg .= ' Your earlier copy was kept.';
            }
            return ['flash' => ['kind' => 'error', 'message' => $msg . $note], 'query' => $query];
        }
        if ((int) $result['calls'] === 0) {
            $msg = 'No term overlaps these dates, so OSM was not called. The preview shows an empty copy for the sections you chose.';
        } else {
            $msg = 'Loaded ' . self::nameList($result['loaded']) . ' using ' . (int) $result['calls']
                . ' OSM request(s). The preview and the CSV downloads use this copy and do not call OSM.';
        }
        return ['flash' => ['kind' => 'ok', 'message' => $msg . $note], 'query' => $query];
    }

    /** @param array<string, mixed> $query @return array{ok:bool,message:string,query:array<string,mixed>,from?:string,to?:string,register?:list<array<string,mixed>>,hmrc?:list<array<string,mixed>>} */
    public static function exportData(array $query): array
    {
        [$choices] = self::choices();
        [$from, $to, $rangeError] = self::range($query, false);
        $charity = self::charityFrom($query);
        $selected = self::selectedIds($choices, $query + ['show' => '1']);
        $picked = LoginSections::pickedSections($choices, $selected);
        $ids = array_column($picked, 'section_id');
        $back = ($from !== null && $to !== null) ? self::redirectQuery($from, $to, $charity, $ids) : [];
        if ($rangeError !== null || $from === null || $to === null) {
            return ['ok' => false, 'message' => $rangeError ?? 'Enter both dates as dd/mm/yyyy, for example 06/04/2026.', 'query' => $back];
        }
        $people = [];
        $any = false;
        foreach ($picked as $section) {
            $cache = GiftAidLoader::cached((string) $section['section_id']);
            if ($cache === null || !GiftAidLoader::covers($cache, $from, $to)) {
                continue;
            }
            $any = true;
            foreach ($cache['declarations'] as $row) {
                if (is_array($row)) {
                    $people[] = $row;
                }
            }
        }
        if (!$any) {
            return ['ok' => false, 'message' => 'There is no Gift Aid copy for these dates yet. Choose Load Gift Aid first, then download the CSV.', 'query' => $back];
        }
        $report = GiftAidGrid::report($people, $from, $to, $charity);
        return ['ok' => true, 'message' => '', 'query' => $back, 'from' => $from, 'to' => $to, 'register' => $report['register'], 'hmrc' => $report['hmrc']];
    }

    /**
     * From the query. [fromIso, toIso, error, swapped]. Both ends are required.
     * Empty values use the current UK tax year when $defaultTaxYear is set.
     *
     * @param array<string, mixed> $q
     * @return array{0:?string,1:?string,2:?string,3:bool}
     */
    public static function range(array $q, bool $defaultTaxYear): array
    {
        $fromRaw = is_string($q['from'] ?? null) ? trim($q['from']) : '';
        $toRaw = is_string($q['to'] ?? null) ? trim($q['to']) : '';
        if ($fromRaw === '' && $toRaw === '' && $defaultTaxYear) {
            [$from, $to] = GiftAidGrid::taxYearRange();
            return [$from, $to, null, false];
        }
        $from = $fromRaw === '' ? null : GiftAidGrid::isoDate($fromRaw);
        $to = $toRaw === '' ? null : GiftAidGrid::isoDate($toRaw);
        if ($from === null || $to === null) {
            return [null, null, 'Enter both dates as dd/mm/yyyy, for example 06/04/2026.', false];
        }
        $swapped = false;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
            $swapped = true;
        }
        return [$from, $to, null, $swapped];
    }

    public static function requestNote(int $count, bool $fallback): string
    {
        $noun = $count === 1 ? 'OSM request' : 'OSM requests';
        if ($count === 0) {
            return 'This load will make 0 OSM requests. No stored term overlaps these dates, so Load saves an empty copy and does not call OSM.';
        }
        if ($fallback) {
            return 'This load will make ' . $count . ' ' . $noun . '. Where this sign-in has no term dates, only the current term is requested.';
        }
        return 'This load will make ' . $count . ' ' . $noun . ', one for each section term that overlaps these dates.';
    }

    private function sendExport(string $kind): void
    {
        Auth::requireLogin();
        $export = self::exportData($_GET);
        if (!$export['ok']) {
            $_SESSION['giftAidFlash'] = ['kind' => 'error', 'message' => $export['message']];
            header('Location: /gift-aid/' . ($export['query'] !== [] ? '?' . http_build_query($export['query']) : ''));
            exit;
        }
        $body = $kind === 'hmrc'
            ? GiftAidGrid::hmrcCsv($export['hmrc'])
            : GiftAidGrid::declarationsCsv($export['register']);
        $filename = GiftAidGrid::csvFilename($kind, (string) $export['from'], (string) $export['to']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
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

    /** @param array<string, mixed> $query */
    private static function charityFrom(array $query): string
    {
        $posted = is_string($query['charity'] ?? null) ? trim($query['charity']) : '';
        if ($posted !== '') {
            $charity = GiftAidGrid::claimCharity($posted);
            $_SESSION['giftAidClaimCharity'] = $charity;
            return $charity;
        }
        $saved = $_SESSION['giftAidClaimCharity'] ?? null;
        if (is_string($saved) && trim($saved) !== '') {
            return GiftAidGrid::claimCharity($saved);
        }
        return GiftAidGrid::claimCharity(null);
    }

    /**
     * @param list<array<string, mixed>> $choices
     * @param array<string, mixed> $query
     * @return list<string>
     */
    private static function selectedIds(array $choices, array $query): array
    {
        $explicit = array_key_exists('show', $query) || array_key_exists('section_ids', $query);
        if (!$explicit) {
            return array_column($choices, 'id');
        }
        $raw = $query['section_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        return array_column(LoginSections::pickedSections($choices, $raw), 'section_id');
    }

    /** @param list<string> $ids @return array<string, mixed> */
    private static function redirectQuery(string $from, string $to, string $charity, array $ids): array
    {
        return [
            'show' => '1',
            'from' => GiftAidGrid::ukDate($from),
            'to' => GiftAidGrid::ukDate($to),
            'charity' => $charity,
            'section_ids' => array_values($ids),
        ];
    }

    /** @param array<string, mixed> $post @param list<string> $ids @return array<string, mixed> */
    private static function rawQuery(array $post, string $charity, array $ids): array
    {
        $q = ['show' => '1', 'charity' => $charity, 'section_ids' => array_values($ids)];
        foreach (['from', 'to'] as $key) {
            if (isset($post[$key]) && is_string($post[$key]) && $post[$key] !== '') {
                $q[$key] = mb_substr(trim($post[$key]), 0, 20);
            }
        }
        return $q;
    }

    /** @param list<string> $names */
    private static function nameList(array $names): string
    {
        $names = array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $names), static fn (string $n): bool => $n !== ''));
        if ($names === []) {
            return 'the sections you chose';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** @return array{0: list<array<string,mixed>>, 1: ?string} */
    private static function choices(): array
    {
        $stored = LoginSections::fromSession();
        if ($stored === null) {
            return [[], 'Sign out and sign in again so Gift Aid can use the sections from this login.'];
        }
        return [LoginSections::memberListChoices($stored), null];
    }
}
