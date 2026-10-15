<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Http\Csrf;
use App\Members\ContactIndex;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use Throwable;

/**
 * Find a member by postcode, phone number or parent/contact name.
 * GET searches the session index only (no OSM calls). POST /find-member/refresh rebuilds it.
 */
final class FindMemberController
{
    private const TYPES = ['any', 'postcode', 'phone', 'name'];

    public function index(): void
    {
        $token = Auth::requireLogin();
        [$choices, $sectionError] = self::choices($token);
        $idx = self::index_();
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }
        $type = (string) ($_GET['type'] ?? 'any');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'any';
        }
        $results = null;
        if ($q !== '' && $idx !== null) {
            // Only sections still on this login (permissions unchanged since the index was built).
            $allowed = array_column($choices, 'id');
            $rows = array_values(array_filter($idx['rows'], static fn ($r) => in_array((string) $r['sectionId'], $allowed, true)));
            $results = ContactIndex::search($rows, $q, $type);
        }
        $flash = $_SESSION['findMemberFlash'] ?? null;
        unset($_SESSION['findMemberFlash']);
        $groups = LoginSections::groupChoices($choices);
        App::render('find-member.twig', Auth::baseContext([
            'title' => 'Find a member',
            'groupName' => (string) ($_SESSION['groupName'] ?? 'OSM Helper'),
            'groups' => $groups,
            'multiGroup' => count($groups) > 1,
            'choiceCount' => count($choices),
            'sectionError' => $sectionError,
            'selectedIds' => $idx['sectionIds'] ?? [],
            'indexed' => $idx !== null,
            'indexInfo' => $idx === null ? null : [
                'builtAt' => $idx['builtAt'], 'loaded' => $idx['loaded'], 'skipped' => $idx['skipped'],
                'memberCount' => count(array_unique(array_column($idx['rows'], 'scoutid'))),
            ],
            'flash' => is_array($flash) ? $flash : null,
            'q' => $q,
            'type' => $type,
            'results' => $results,
            'cooldownMinutes' => (int) (ContactIndex::REFRESH_COOLDOWN_SEC / 60),
        ]));
    }

    public function refresh(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        [$choices, $sectionError] = self::choices($token);
        $back = static function (string $kind, string $msg): void {
            $_SESSION['findMemberFlash'] = ['kind' => $kind, 'message' => $msg];
            header('Location: /find-member/');
            exit;
        };
        if ($sectionError !== null) {
            $back('error', $sectionError);
        }
        $last = (int) ($_SESSION['findMemberLastRefresh'] ?? 0);
        $wait = ContactIndex::REFRESH_COOLDOWN_SEC - (time() - $last);
        if ($last > 0 && $wait > 0) {
            $back('error', 'Contacts were loaded a moment ago. To protect your OSM allowance you can load again in ' . (int) ceil($wait / 60) . ' min.');
        }
        $raw = $_POST['section_ids'] ?? [];
        $picked = LoginSections::pickedSections($choices, is_array($raw) ? $raw : []);
        if ($picked === []) {
            $back('error', 'Tick at least one section, then load contacts.');
        }
        $_SESSION['findMemberLastRefresh'] = time();
        $built = ContactIndex::build(new OsmApi(), $token, $picked);
        $now = time();
        $kept = false;
        if (ContactIndex::shouldReplace($built)) {
            $_SESSION[ContactIndex::SESSION_KEY] = [
                'builtAt' => (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/London'))->format('d/m/y H:i'),
                'builtAtTs' => $now,
                'sectionIds' => array_map(static fn ($s) => (string) $s['section_id'], $picked),
                'rows' => $built['rows'],
                'loaded' => $built['loaded'],
                'skipped' => $built['skipped'],
            ];
        } else {
            $kept = ContactIndex::load($now) !== null; // earlier good index is left untouched
        }
        $note = !empty($built['rateUnknown']) ? ' OSM did not report your remaining allowance, so OSMHelper paused longer between sections.' : '';
        if ($built['stopReason'] !== null) {
            $back('error', $built['stopReason'] . ' Sections loaded: ' . (implode(', ', $built['loaded']) ?: 'none') . '.'
                . ($kept ? ' Your earlier contacts are still available for searching.' : '') . $note);
        }
        $back('ok', 'Contacts loaded for ' . count($built['loaded']) . ' section(s) using ' . $built['calls'] . ' OSM request(s). Searching uses this copy and does not call OSM.' . $note);
    }

    /** @return array<string, mixed>|null */
    private static function index_(): ?array
    {
        return ContactIndex::load();
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
