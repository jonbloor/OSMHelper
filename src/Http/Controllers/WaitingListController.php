<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Config;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\OsmApi;
use App\Store\Db;
use App\Store\SettingsStore;
use App\Store\WaitingFieldMapStore;
use App\Waiting\WaitingListService as WL;
use Throwable;
final class WaitingListController
{
    /**
     * Kill switch for rank/notes writes to OSM. Also disable with env WAITING_RANK_WRITES=off.
     * Enabled after Jon's browser capture of updateColumn (2026-09-26).
     */
    private const WRITES_ENABLED = true;
    /** Refuse fan-out / writes at or below this many remaining OSM requests (matches Top awards). */
    private const RATE_LOW_REMAINING = 40;
    /** Spacing between writes (150–200 ms). */
    private const WRITE_DELAY_US = 175000;
    /** Review → confirm must happen within this many seconds. */
    private const PENDING_TTL_SEC = 1800;
    /** Upper bound on cells per save (keeps quota use predictable). */
    private const MAX_CELLS_PER_SAVE = 150;
    /** Gap between two writes to the SAME member (rank then notes): OSM can lose the first if they arrive too close together. */
    private const SAME_MEMBER_DELAY_US = 1200000;
    /** Wait before re-reading OSM to check the saved values stuck. */
    private const VERIFY_DELAY_US = 1000000;
    /** Shown instead of raw database errors (details go to the PHP error log). */
    private const MAP_LOAD_ERROR = "We couldn't load your saved waiting list settings. This is a problem on our side, and we've been told. You can still view the waiting list.";
    private const MAP_SAVE_ERROR = "We couldn't save your waiting list settings. This is a problem on our side, and we've been told. Please try again later.";

    /** @return array<string, float|int> */
    private static function cutoffs(): array
    {
        $saved = SettingsStore::all();
        return array_merge(Config::DEFAULT_CUTOFFS, is_array($saved['cutoffs'] ?? null) ? $saved['cutoffs'] : []);
    }

    /**
     * Sections → waiting lists → selected list. Renders the error page and returns null on failure.
     * @return array{api:OsmApi, lists:list<array<string,string>>, list:array<string,string>, usedNameFallback:bool}|null
     */
    private static function resolve(string $token, ?string $requested): ?array
    {
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Waiting list',
                'message' => 'Could not load sections from OSM.',
            ]));
            return null;
        }
        $found = WL::discoverLists($sections);
        $remembered = isset($_SESSION['waitingSectionId']) && is_string($_SESSION['waitingSectionId']) ? $_SESSION['waitingSectionId'] : null;
        $list = WL::pickList($found['lists'], $requested, $remembered);
        if ($list === null) {
            App::render('error.twig', Auth::baseContext([
                'title' => 'Waiting list',
                'message' => 'We couldn’t find a waiting list in OSM for your account. Check in OSM that you can see your group’s waiting list, then sign out and sign in again.',
            ]));
            return null;
        }
        if ($requested !== null && $requested !== '' && $requested === $list['id']) {
            $_SESSION['waitingSectionId'] = $list['id'];
        }
        return ['api' => $api, 'lists' => $found['lists'], 'list' => $list, 'usedNameFallback' => $found['usedNameFallback']];
    }

    private static function requestedSection(): ?string
    {
        $raw = $_GET['section'] ?? $_POST['section'] ?? null;
        return is_string($raw) && preg_match('/^\d{1,12}$/', $raw) ? $raw : null;
    }

    /** @return array{map: ?array<string,string>, error: ?string} */
    private static function mapping(array $list): array
    {
        try {
            return ['map' => WaitingFieldMapStore::get(WL::groupKey($list), $list['id']), 'error' => null];
        } catch (Throwable $e) {
            // Never show raw SQL/PDO errors to users; log the detail for us instead.
            error_log('OSMHelper waiting list: could not read saved field settings (group ' . WL::groupKey($list)
                . ', section ' . (string) ($list['id'] ?? '') . ', db ' . Db::path() . '): '
                . get_class($e) . ': ' . $e->getMessage());
            return ['map' => null, 'error' => self::MAP_LOAD_ERROR];
        }
    }

    private static function flash(string $type, string $message): void
    {
        $_SESSION['waitingFlash'] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    private static function takeFlash(): ?array
    {
        $f = $_SESSION['waitingFlash'] ?? null;
        unset($_SESSION['waitingFlash']);
        return is_array($f) ? $f : null;
    }

    private static function redirect(string $path, string $sectionId): void
    {
        header('Location: ' . $path . '?section=' . rawurlencode($sectionId));
        exit;
    }

    private static function updatedBy(): string
    {
        $name = trim((string) ($_SESSION['fullName'] ?? ''));
        $uid = trim((string) ($_SESSION['osmUserId'] ?? ''));
        $who = $name !== '' ? $name : 'Unknown user';
        return $uid !== '' ? ($who . ' (OSM user ' . $uid . ')') : $who;
    }

    /** @return array<string, mixed> */
    private static function pickerContext(array $ctx): array
    {
        return [
            'waitingLists' => $ctx['lists'],
            'waitingList' => $ctx['list'],
            'usedNameFallback' => $ctx['usedNameFallback'],
        ];
    }

    // ------------------------------------------------------------------ scored list (existing)

    public function index(): void
    {
        $token = Auth::requireLogin();
        $cutoffs = self::cutoffs();
        $ctx = self::resolve($token, self::requestedSection());
        if ($ctx === null) return;
        $waiting = $ctx['list'];
        $mapping = self::mapping($waiting);
        $loaded = WL::loadApplicants($ctx['api'], $token, $waiting, $cutoffs, $mapping['map']);
        $map = $mapping['map'];
        $applicants = $loaded['applicants'];
        if ($map !== null) {
            foreach ($applicants as &$a) {
                $a['osmRank'] = (string) ($a['custom'][$map['rank_column_id']] ?? '');
            }
            unset($a);
        }

        App::render('waiting-list.twig', Auth::baseContext(array_merge(self::pickerContext($ctx), [
            'title' => 'Waiting list',
            'applicants' => $applicants,
            'applicantCount' => count($applicants),
            'listCount' => $loaded['listCount'],
            'waitingSectionName' => (string) ($waiting['name'] ?? 'Waiting list'),
            'listError' => $loaded['listError'],
            'fieldMap' => $map,
            'mapError' => $mapping['error'],
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ])));
    }

    // ------------------------------------------------------------------ field mapping

    /**
     * Custom (group 5) columns for a list, read from one applicant's getData (2 OSM calls).
     * @return array{groupId:string, columns:list<array<string,mixed>>, error:?string}
     */
    private static function listColumns(OsmApi $api, string $token, array $list): array
    {
        try {
            $rows = WL::fetchListRows($api, $token, $list);
        } catch (Throwable $e) {
            return ['groupId' => WL::FIELD_GROUP_ID, 'columns' => [], 'error' => 'Could not load the waiting list from OSM: ' . $e->getMessage()];
        }
        $first = null;
        foreach ($rows as $r) {
            $sid = is_array($r) ? ($r['scoutid'] ?? $r['id'] ?? null) : null;
            if ($sid !== null && (string) $sid !== '') { $first = (string) $sid; break; }
        }
        if ($first === null) {
            return ['groupId' => WL::FIELD_GROUP_ID, 'columns' => [], 'error' => 'This waiting list has nobody on it, so OSMHelper cannot read its custom fields yet. Add (or wait for) one applicant, then come back.'];
        }
        try {
            $res = WL::fetchCustomData($api, $token, $list['id'], $first);
        } catch (Throwable $e) {
            return ['groupId' => WL::FIELD_GROUP_ID, 'columns' => [], 'error' => 'Could not read custom fields from OSM: ' . $e->getMessage()];
        }
        $cols = WL::extractCustomColumns($res);
        if ($cols['columns'] === []) {
            return ['groupId' => $cols['groupId'], 'columns' => [], 'error' => 'This waiting list has no custom fields in OSM yet. In OSM, open the waiting list’s section settings, go to Customisable data, add two text fields called Rank and Notes, then reload this page.'];
        }
        return $cols + ['error' => null];
    }

    public function fields(): void
    {
        $token = Auth::requireLogin();
        $ctx = self::resolve($token, self::requestedSection());
        if ($ctx === null) return;
        $list = $ctx['list'];
        $mapping = self::mapping($list);
        $cols = self::listColumns($ctx['api'], $token, $list);
        $map = $mapping['map'];
        $rankSel = $map['rank_column_id'] ?? WL::suggestColumn($cols['columns'], 'rank');
        $notesSel = $map['notes_column_id'] ?? WL::suggestColumn($cols['columns'], 'notes', $rankSel);
        $willingSel = $map !== null ? ($map['willing_column_id'] ?? '') : (WL::suggestColumn($cols['columns'], 'willing') ?? '');
        App::render('waiting-fields.twig', Auth::baseContext(array_merge(self::pickerContext($ctx), [
            'title' => 'Waiting list fields',
            'flash' => self::takeFlash(),
            'columns' => $cols['columns'],
            'fieldGroupId' => $cols['groupId'],
            'columnsError' => $cols['error'],
            'fieldMap' => $map,
            'mapError' => $mapping['error'],
            'rankSelected' => (string) ($rankSel ?? ''),
            'notesSelected' => (string) ($notesSel ?? ''),
            'willingSelected' => (string) $willingSel,
            'updatedAtUk' => self::ukTime((string) ($map['updated_at'] ?? '')),
            'suggested' => $map === null,
        ])));
    }

    public function saveFields(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $ctx = self::resolve($token, self::requestedSection());
        if ($ctx === null) return;
        $list = $ctx['list'];
        $rankId = trim((string) ($_POST['rank_column_id'] ?? ''));
        $notesId = trim((string) ($_POST['notes_column_id'] ?? ''));
        $willingId = trim((string) ($_POST['willing_column_id'] ?? '')); // optional
        if (self::requestedSection() !== $list['id']) {
            self::flash('error', 'That waiting list is not one this login can see.');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        if ($rankId === '' || $notesId === '') {
            self::flash('error', 'Pick both a Rank field and a Notes field.');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        if ($rankId === $notesId) {
            self::flash('error', 'Rank and Notes must be two different custom fields.');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        $cols = self::listColumns($ctx['api'], $token, $list);
        $byId = [];
        foreach ($cols['columns'] as $c) $byId[$c['column_id']] = $c;
        if (!isset($byId[$rankId]) || !isset($byId[$notesId])) {
            self::flash('error', 'Those field ids were not found in this waiting list’s custom fields in OSM'
                . ($cols['error'] ? (': ' . $cols['error']) : '.') . ' Nothing saved.');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        if ($willingId !== '' && ($willingId === $rankId || $willingId === $notesId)) {
            self::flash('error', 'Willing to help must be a different custom field from Rank and Notes (or leave it as “Not used”).');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        if ($willingId !== '' && (!isset($byId[$willingId]) || !empty($byId[$willingId]['read_only']))) {
            self::flash('error', 'That Willing to help field was not found in OSM or is read-only. Nothing saved.');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        if (!empty($byId[$rankId]['read_only']) || !empty($byId[$notesId]['read_only'])) {
            self::flash('error', 'OSM marks one of those fields read-only. Pick editable fields.');
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        try {
            WaitingFieldMapStore::save(WL::groupKey($list), $list['id'], [
                'section_name' => $list['name'],
                'rank_column_id' => $rankId,
                'rank_varname' => $byId[$rankId]['varname'],
                'rank_label' => $byId[$rankId]['label'],
                'notes_column_id' => $notesId,
                'notes_varname' => $byId[$notesId]['varname'],
                'notes_label' => $byId[$notesId]['label'],
                'willing_column_id' => $willingId,
                'willing_varname' => $willingId !== '' ? $byId[$willingId]['varname'] : '',
                'willing_label' => $willingId !== '' ? $byId[$willingId]['label'] : '',
                'field_group_id' => $cols['groupId'],
            ], self::updatedBy());
        } catch (Throwable $e) {
            error_log('OSMHelper waiting list: could not save field settings (group ' . WL::groupKey($list)
                . ', section ' . (string) ($list['id'] ?? '') . ', db ' . Db::path() . '): '
                . get_class($e) . ': ' . $e->getMessage());
            self::flash('error', self::MAP_SAVE_ERROR);
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        self::flash('info', 'Saved. Rank uses “' . $byId[$rankId]['label'] . '”, Notes uses “' . $byId[$notesId]['label'] . '”'
            . ($willingId !== '' ? ', Willing to help uses “' . $byId[$willingId]['label'] . '”' : ', Willing to help is not used')
            . '. Shared with everyone in your OSM group who uses OSMHelper.');
        self::redirect('/waiting-list/rank/', $list['id']);
    }

    // ------------------------------------------------------------------ rank view

    public function rank(): void
    {
        $token = Auth::requireLogin();
        unset($_SESSION['waitingRankPending']); // a fresh ranking page invalidates any earlier review
        $ctx = self::resolve($token, self::requestedSection());
        if ($ctx === null) return;
        $list = $ctx['list'];
        $mapping = self::mapping($list);
        $map = $mapping['map'];
        if ($map === null) {
            if ($mapping['error'] === null) {
                self::flash('info', 'First choose which OSM custom fields hold Rank and Notes for this waiting list.');
            } else {
                self::flash('error', $mapping['error']);
            }
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        $api = $ctx['api'];
        $access = WL::saveAccess();
        $writesOn = WL::writesEnabled(self::WRITES_ENABLED);
        $base = array_merge(self::pickerContext($ctx), [
            'title' => 'Waiting list ranking',
            'flash' => self::takeFlash(),
            'fieldMap' => $map,
            'access' => $access,
            'writesEnabled' => $writesOn,
        ]);
        if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
            App::render('waiting-rank.twig', Auth::baseContext(array_merge($base, [
                'rows' => [],
                'rateBlocked' => true,
            ])));
            return;
        }
        $loaded = WL::loadApplicants($api, $token, $list, self::cutoffs(), $map);
        $rows = [];
        $anyReadable = false;
        $anyHasColumns = false;
        foreach ($loaded['applicants'] as $a) {
            $custom = is_array($a['custom']) ? $a['custom'] : [];
            $hasCols = array_key_exists($map['rank_column_id'], $custom) && array_key_exists($map['notes_column_id'], $custom);
            if ($a['customOk']) $anyReadable = true;
            if ($hasCols) $anyHasColumns = true;
            $rows[] = $a + [
                'rankValue' => (string) ($custom[$map['rank_column_id']] ?? ''),
                'notesValue' => (string) ($custom[$map['notes_column_id']] ?? ''),
                'willingValue' => ($map['willing_column_id'] ?? '') !== '' ? (string) ($custom[$map['willing_column_id']] ?? '') : '',
                'editable' => $a['customOk'] && $hasCols,
            ];
        }
        $mappingBroken = $anyReadable && !$anyHasColumns;
        $rows = WL::sortForRank($rows);
        $renumber = isset($_GET['renumber']) && (string) $_GET['renumber'] === '1';
        $proposed = $renumber ? WL::renumber(array_values(array_filter($rows, static fn ($r) => $r['editable']))) : [];
        $proposedChanges = 0;
        foreach ($rows as &$r) {
            $r['inputRank'] = $proposed[$r['scoutid']] ?? $r['rankValue'];
            if (isset($proposed[$r['scoutid']]) && WL::rankComparable($r['rankValue']) !== $proposed[$r['scoutid']]) {
                $proposedChanges++;
            }
        }
        unset($r);

        App::render('waiting-rank.twig', Auth::baseContext(array_merge($base, [
            'rows' => $rows,
            'rateBlocked' => false,
            'listError' => $loaded['listError'],
            'mappingBroken' => $mappingBroken,
            'ties' => WL::tiedRanks($rows),
            'renumber' => $renumber,
            'proposedChanges' => $proposedChanges,
            'notesMax' => WL::NOTES_MAX_LEN,
            'noteEntryMax' => WL::NOTE_ENTRY_MAX_LEN,
            'fetchedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('d/m/y H:i'),
        ])));
    }

    // ------------------------------------------------------------------ review (re-read) + apply

    public function review(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $ctx = self::resolve($token, self::requestedSection());
        if ($ctx === null) return;
        $list = $ctx['list'];
        if (self::requestedSection() !== $list['id']) {
            self::flash('error', 'That waiting list is not one this login can see.');
            self::redirect('/waiting-list/rank/', $list['id']);
        }
        $map = self::mapping($list)['map'];
        if ($map === null) {
            self::redirect('/waiting-list/fields/', $list['id']);
        }
        $edited = WL::editedCells($_POST);
        if (($map['willing_column_id'] ?? '') === '') {
            $edited['changes'] = array_values(array_filter($edited['changes'], static fn ($c) => $c['field'] !== 'willing'));
        }
        $base = array_merge(self::pickerContext($ctx), [
            'title' => 'Review rank and notes changes',
            'fieldMap' => $map,
            'access' => WL::saveAccess(),
            'writesEnabled' => WL::writesEnabled(self::WRITES_ENABLED),
            'errors' => $edited['errors'],
        ]);
        if ($edited['errors'] !== []) {
            App::render('waiting-review.twig', Auth::baseContext(array_merge($base, ['rows' => [], 'noopCount' => 0])));
            return;
        }
        if ($edited['changes'] === []) {
            self::flash('info', 'No changes to save.');
            self::redirect('/waiting-list/rank/', $list['id']);
        }
        if (count($edited['changes']) > self::MAX_CELLS_PER_SAVE) {
            App::render('waiting-review.twig', Auth::baseContext(array_merge($base, ['rows' => [], 'noopCount' => 0,
                'errors' => ['That is ' . count($edited['changes']) . ' changed cells; save at most ' . self::MAX_CELLS_PER_SAVE . ' at a time to protect your OSM allowance.']])));
            return;
        }
        $api = $ctx['api'];
        $members = array_values(array_unique(array_map(static fn ($c) => $c['scoutid'], $edited['changes'])));
        $snap = $api->getRateLimitSnapshot();
        $needed = 1 + count($members);
        if ($snap !== null && $snap['remaining'] !== null && $snap['remaining'] < $needed + self::RATE_LOW_REMAINING) {
            App::render('waiting-review.twig', Auth::baseContext(array_merge($base, ['rows' => [], 'noopCount' => 0,
                'errors' => ['OSM allowance is low (' . $snap['remaining'] . ' remaining). Checking these changes needs about ' . $needed . ' requests. Wait for it to reset (see Home), then use your browser Back button and submit again.']])));
            return;
        }
        // Re-read: who is still on the list, and current OSM values for each changed member.
        $reread = self::reread($api, $token, $list, $map, $members);
        if ($reread['error'] !== null) {
            App::render('waiting-review.twig', Auth::baseContext(array_merge($base, ['rows' => [], 'noopCount' => 0,
                'errors' => [$reread['error']]])));
            return;
        }
        $names = $reread['names'];
        $current = $reread['current'];
        $notes = $reread['notes'];
        $reviewed = WL::reviewChanges($edited['changes'], $current);
        $rows = [];
        $noop = 0;
        $now = new \DateTimeImmutable('now');
        $fullName = (string) ($_SESSION['fullName'] ?? '');
        foreach ($reviewed as $r) {
            if ($r['noop']) { $noop++; continue; }
            if ($r['field'] === 'notes') {
                // New entry goes on top of the value in OSM now; history below is kept as is.
                $r['entry'] = $r['new'];
                $r['new'] = WL::composeNote($r['entry'], $fullName, $r['current'], $now);
                if (!$r['missing'] && mb_strlen($r['new']) > WL::NOTES_MAX_LEN) {
                    $r['missing'] = true;
                    $notes[$r['scoutid']] = 'With this note the notes would be ' . mb_strlen($r['new']) . ' characters (limit '
                        . WL::NOTES_MAX_LEN . '). Not saved, so no history is cut off: tidy the notes in OSM first.';
                }
            }
            $rows[] = $r + [
                'idx' => count($rows),
                'name' => $names[$r['scoutid']] ?? ('Member ' . $r['scoutid']),
                'columnId' => self::columnFor($map, (string) $r['field']),
                'fieldLabel' => self::fieldLabel($map, (string) $r['field']),
                'scoreEffect' => $r['field'] === 'willing' && !$r['missing']
                    ? ((WL::isWilling($r['new']) ? WL::WILLING_BONUS : 0) - (WL::isWilling($r['current']) ? WL::WILLING_BONUS : 0))
                    : null,
                'note' => $notes[$r['scoutid']] ?? '',
            ];
        }
        // Session keeps ids, the new value and a keyed hash of the OSM value seen now; no applicant names.
        // Note: for notes rows the "new value" is the composed notes text (the new dated entry plus the
        // existing OSM notes history), so that text stays in the session until apply or the 30-minute
        // expiry (it is cleared on apply and when the ranking page is reloaded). Apply re-reads OSM for
        // names and current values.
        $hashKey = bin2hex(random_bytes(16));
        $pendingRows = [];
        foreach ($rows as $r) {
            if (!empty($r['missing'])) continue;
            $pendingRows[] = [
                'idx' => $r['idx'],
                'scoutid' => $r['scoutid'],
                'field' => $r['field'],
                'columnId' => $r['columnId'],
                'new' => $r['new'],
                'seenHash' => self::valueHash($hashKey, (string) $r['current']),
            ];
        }
        $_SESSION['waitingRankPending'] = [
            'at' => time(),
            'sectionId' => $list['id'],
            'fieldGroupId' => $map['field_group_id'] !== '' ? $map['field_group_id'] : WL::FIELD_GROUP_ID,
            'hashKey' => $hashKey,
            'rows' => $pendingRows,
        ];
        App::render('waiting-review.twig', Auth::baseContext(array_merge($base, [
            'rows' => $rows,
            'noopCount' => $noop,
            'testMember' => self::testMember(),
        ])));
    }

    private static function valueHash(string $key, string $value): string
    {
        return hash_hmac('sha256', $value, $key);
    }

    private static function columnFor(array $map, string $field): string
    {
        return match ($field) {
            'rank' => (string) $map['rank_column_id'],
            'notes' => (string) $map['notes_column_id'],
            'willing' => (string) ($map['willing_column_id'] ?? ''),
            default => '',
        };
    }

    /** Stored UTC "Y-m-d H:i:s" → "26/09/26 at 13:40" UK time ('' if unknown). */
    private static function ukTime(string $utc): string
    {
        if ($utc === '') return '';
        try {
            $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return '';
        }
        $d = $d->setTimezone(new \DateTimeZone('Europe/London'));
        return $d->format('d/m/y') . ' at ' . $d->format('H:i');
    }

    private static function fieldLabel(array $map, string $field): string
    {
        if ($field === 'willing') return ($map['willing_label'] ?? '') !== '' ? $map['willing_label'] : 'Willing to help';
        if ($field === 'rank') return $map['rank_label'] !== '' ? $map['rank_label'] : 'Rank';
        return $map['notes_label'] !== '' ? $map['notes_label'] : 'Notes';
    }

    /**
     * Re-read OSM: names of who is on the list now, and current rank/notes values for the given members.
     * Costs 1 + count($members) requests.
     * @param list<string> $members
     * @return array{error:?string, names:array<string,string>, current:array<string,?array{rank:?string,notes:?string}>, notes:array<string,string>}
     */
    private static function reread(OsmApi $api, string $token, array $list, array $map, array $members): array
    {
        $names = [];
        try {
            foreach (WL::fetchListRows($api, $token, $list) as $r) {
                $sid = is_array($r) ? (string) ($r['scoutid'] ?? $r['id'] ?? '') : '';
                if ($sid !== '') $names[$sid] = trim((string) ($r['firstname'] ?? '') . ' ' . (string) ($r['lastname'] ?? ''));
            }
        } catch (Throwable $e) {
            return ['error' => 'Could not re-read the waiting list from OSM: ' . $e->getMessage(), 'names' => [], 'current' => [], 'notes' => []];
        }
        $current = [];
        $notes = [];
        foreach ($members as $sid) {
            if (!isset($names[$sid])) {
                $current[$sid] = null;
                $notes[$sid] = 'No longer on this waiting list — skipped.';
                continue;
            }
            try {
                $cols = WL::extractCustomColumns(WL::fetchCustomData($api, $token, $list['id'], $sid));
                $byId = [];
                foreach ($cols['columns'] as $c) $byId[$c['column_id']] = $c['value'];
                $current[$sid] = [
                    'rank' => array_key_exists($map['rank_column_id'], $byId) ? (string) $byId[$map['rank_column_id']] : null,
                    'notes' => array_key_exists($map['notes_column_id'], $byId) ? (string) $byId[$map['notes_column_id']] : null,
                    'willing' => ($map['willing_column_id'] ?? '') !== '' && array_key_exists($map['willing_column_id'], $byId)
                        ? (string) $byId[$map['willing_column_id']] : null,
                ];
                if ($current[$sid]['rank'] === null || $current[$sid]['notes'] === null) {
                    $notes[$sid] = 'Mapped field not found for this member in OSM — skipped. Check Fields.';
                }
            } catch (Throwable $e) {
                $current[$sid] = null;
                $notes[$sid] = 'Could not re-read from OSM — skipped (' . $e->getMessage() . ').';
            }
        }
        return ['error' => null, 'names' => $names, 'current' => $current, 'notes' => $notes];
    }

    /** Optional safety for first live tests: env WAITING_RANK_TEST_MEMBER=<member id> limits writes to that member. */
    private static function testMember(): ?string
    {
        $v = Config::get('WAITING_RANK_TEST_MEMBER');
        return $v !== null && preg_match('/^\d+$/', trim($v)) ? trim($v) : null;
    }

    public function apply(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $pending = $_SESSION['waitingRankPending'] ?? null;
        unset($_SESSION['waitingRankPending']);
        $sectionId = self::requestedSection() ?? '';
        if (!is_array($pending) || empty($pending['rows']) || ($pending['sectionId'] ?? '') !== $sectionId) {
            self::flash('error', 'Nothing to save — review your changes again.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        if ((int) ($pending['at'] ?? 0) < time() - self::PENDING_TTL_SEC) {
            self::flash('error', 'That review expired (30 minutes). Reload the ranking page and try again.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        if (!WL::writesEnabled(self::WRITES_ENABLED)) {
            self::flash('error', 'Saving to OSM is switched off on this server. Nothing was written.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        $access = WL::saveAccess();
        if (!$access['canSave']) {
            self::flash('error', $access['reason'] . ' Nothing was written.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        if (empty($_POST['saved_copy'])) {
            self::flash('error', 'Tick “I have exported a copy” first. Nothing was written.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        $include = is_array($_POST['include'] ?? null) ? array_map('strval', $_POST['include']) : [];
        $selected = [];
        foreach ($pending['rows'] as $r) {
            if (!in_array((string) $r['idx'], $include, true) || !empty($r['missing'])) continue;
            $selected[] = $r;
        }
        if ($selected === []) {
            self::flash('info', 'No rows were ticked, so nothing was written.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        $ctx = self::resolve($token, $sectionId);
        if ($ctx === null) return;
        $list = $ctx['list'];
        $api = $ctx['api'];
        if ($list['id'] !== $sectionId) {
            self::flash('error', 'That waiting list is not one this login can see. Nothing was written.');
            self::redirect('/waiting-list/rank/', $list['id']);
        }
        $map = self::mapping($list)['map'];
        if ($map === null) {
            self::flash('error', 'Field settings for this list could not be read. Nothing was written.');
            self::redirect('/waiting-list/fields/', $sectionId);
        }
        foreach ($selected as $r) {
            $expect = self::columnFor($map, (string) $r['field']);
            if ((string) $r['columnId'] !== (string) $expect) {
                self::flash('error', 'The Rank/Notes field settings changed since you reviewed. Nothing was written — review again.');
                self::redirect('/waiting-list/rank/', $sectionId);
            }
        }
        $members = array_values(array_unique(array_map(static fn ($r) => (string) $r['scoutid'], $selected)));
        $snap = $api->getRateLimitSnapshot();
        // re-read list + each member, the writes, a check re-read per member, and room for one retry each
        $needed = 1 + count($members) + count($selected) + count($members) + count($selected);
        if ($snap !== null && $snap['remaining'] !== null && $snap['remaining'] < $needed + self::RATE_LOW_REMAINING) {
            self::flash('error', 'OSM allowance is low (' . $snap['remaining'] . ' remaining); these ' . count($selected)
                . ' writes need about ' . $needed . ' requests. Nothing was written. Wait for it to reset (see Home), then review again.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        // Names and current values come from a fresh OSM read, not the session.
        $reread = self::reread($api, $token, $list, $map, $members);
        if ($reread['error'] !== null) {
            self::flash('error', $reread['error'] . ' Nothing was written.');
            self::redirect('/waiting-list/rank/', $sectionId);
        }
        $hashKey = (string) ($pending['hashKey'] ?? '');
        $testMember = self::testMember();
        // Write member by member (all of one applicant's cells together) so we can space them out and check them.
        usort($selected, static fn ($x, $y) => [(string) $x['scoutid'], (int) $x['idx']] <=> [(string) $y['scoutid'], (int) $y['idx']]);
        $results = [];
        $stopped = false;
        $written = 0;
        $lastMember = null;
        $sentByMember = []; // scoutid => list of result indexes that OSM accepted
        foreach ($selected as $r) {
            $sid = (string) $r['scoutid'];
            $cur = $reread['current'][$sid] ?? null;
            $now = is_array($cur) ? ($cur[$r['field']] ?? null) : null;
            $row = [
                'name' => $reread['names'][$sid] ?? ('Member ' . $sid), 'scoutid' => $sid,
                'field' => (string) $r['field'], 'columnId' => (string) $r['columnId'],
                'fieldLabel' => self::fieldLabel($map, (string) $r['field']),
                'from' => (string) ($now ?? ''), 'to' => (string) $r['new'],
                'sent' => false, 'ok' => false, 'message' => '', 'raw' => null,
                'returned' => null, 'osmAfter' => null, 'verified' => null, 'retried' => false,
            ];
            if ($stopped) {
                $row['message'] = 'Stopped after the first failure.';
                $results[] = $row;
                continue;
            }
            if ($testMember !== null && $sid !== $testMember) {
                $row['message'] = 'Server limits writes to test member ' . $testMember . '.';
                $results[] = $row;
                continue;
            }
            if ($now === null) {
                $row['message'] = '' . rtrim((string) ($reread['notes'][$sid] ?? 'Could not re-read from OSM'), '.') . '.';
                $results[] = $row;
                continue;
            }
            if ($hashKey === '' || !hash_equals((string) ($r['seenHash'] ?? ''), self::valueHash($hashKey, $now))) {
                $row['message'] = 'Changed in OSM since you reviewed. Reload and check.';
                $results[] = $row;
                continue;
            }
            if ($written > 0) usleep($lastMember === $sid ? self::SAME_MEMBER_DELAY_US : self::WRITE_DELAY_US);
            if ($api->isRateLow(self::RATE_LOW_REMAINING)) {
                $row['message'] = 'OSM allowance became low.';
                $results[] = $row;
                $stopped = true;
                continue;
            }
            $w = WL::writeColumn($api, $token, $sectionId, $sid, (string) $pending['fieldGroupId'], $row['columnId'], $row['to']);
            $written++;
            $lastMember = $sid;
            $row['sent'] = true;
            $row['ok'] = $w['ok'];
            $row['message'] = $w['ok'] ? 'Sent; OSM said OK.' : ('Sent; OSM refused: ' . $w['message']);
            $row['raw'] = $w['raw'];
            $row['returned'] = $w['returned'] ?? null;
            if (!$w['ok']) {
                $stopped = true;
                if ($w['forbidden']) $_SESSION['memberWriteDenied'] = true;
            } else {
                $sentByMember[$sid][] = count($results);
            }
            $results[] = $row;
        }

        // Check in OSM that every accepted value really stuck; if one did not, send it once more.
        if ($sentByMember !== []) {
            $check = function () use ($api, $token, $list, $map, &$results, &$sentByMember): array {
                $bad = [];
                usleep(self::VERIFY_DELAY_US);
                $after = self::reread($api, $token, $list, $map, array_map('strval', array_keys($sentByMember)));
                foreach ($sentByMember as $sid => $idxs) {
                    foreach ($idxs as $i) {
                        $cur = $after['error'] === null ? ($after['current'][(string) $sid] ?? null) : null;
                        $v = is_array($cur) ? ($cur[$results[$i]['field']] ?? null) : null;
                        if ($v === null) { $results[$i]['verified'] = null; continue; }
                        $results[$i]['osmAfter'] = $v;
                        $same = match ($results[$i]['field']) {
                            'rank' => WL::rankComparable($v) === WL::rankComparable($results[$i]['to']),
                            'willing' => trim($v) === trim($results[$i]['to']),
                            default => WL::normaliseNotes($v) === WL::normaliseNotes($results[$i]['to']),
                        };
                        $results[$i]['verified'] = $same;
                        if (!$same) $bad[(string) $sid][] = $i;
                    }
                }
                return $bad;
            };
            $bad = $check();
            if ($bad !== [] && !$api->isRateLow(self::RATE_LOW_REMAINING)) {
                $first = true;
                foreach ($bad as $sid => $idxs) {
                    foreach ($idxs as $i) {
                        if (!$first) usleep(self::SAME_MEMBER_DELAY_US);
                        $first = false;
                        $w = WL::writeColumn($api, $token, $sectionId, (string) $sid, (string) $pending['fieldGroupId'], $results[$i]['columnId'], $results[$i]['to']);
                        $results[$i]['retried'] = true;
                        $results[$i]['raw'] = $w['raw'];
                        $results[$i]['returned'] = $w['returned'] ?? null;
                    }
                }
                $sentByMember = $bad; // re-check only the cells we sent again
                $bad = $check();
            }
            foreach ($results as &$x) {
                if (!$x['sent'] || !$x['ok']) continue;
                if ($x['verified'] === true) {
                    $x['message'] = $x['retried'] ? '— did not stick the first time, so we sent it again; now checked in OSM.' : '— checked in OSM.';
                } elseif ($x['verified'] === false) {
                    $x['ok'] = false;
                    $x['message'] = 'Sent; OSM said OK' . ($x['retried'] ? ' (twice)' : '') . ', but OSM now shows a different value, so it did not stick.';
                } else {
                    $x['message'] = 'Sent; OSM said OK, but we could not re-read it to check.';
                }
            }
            unset($x);
        }
        unset($_SESSION['waitingRankPending']); // cleared at start too; make sure nothing survives apply
        $ok = count(array_filter($results, static fn ($x) => $x['ok']));
        App::render('waiting-result.twig', Auth::baseContext([
            'title' => 'Save results',
            'results' => $results,
            'okCount' => $ok,
            'failCount' => count($results) - $ok,
            'stopped' => $stopped,
            'sectionId' => $sectionId,
        ]));
    }
}
