<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\App;
use App\Http\Auth;
use App\Http\Csrf;
use App\Osm\OsmApi;
use App\Store\WaitingFieldMapStore;
use App\Store\WordpressSiteKeyStore;
use App\Waiting\WaitingListService as WL;
use Throwable;

/**
 * WordPress joining (waiting-list) form: plugin download, site key + waiting-list section,
 * setup steps and troubleshooting. Signed-in leaders only.
 *
 * The plugin zip lives in downloads/ (outside public/, so it is never served as a static file).
 * Refresh it with deploy/refresh-wordpress-plugin.sh.
 */
final class WordpressFormController
{
    public const PAGE = '/wordpress-form/';
    public const ENDPOINT = 'https://osmhelper.co.uk/api/waiting-list/submit';
    public const BASE_URL = 'https://osmhelper.co.uk';

    public function index(): void
    {
        $token = Auth::requireLogin();
        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            $sections = [];
        }
        $waitingLists = WL::discoverLists($sections)['lists'];
        $osmUserId = (string) ($_SESSION['osmUserId'] ?? '');
        $wpRow = $osmUserId !== '' ? WordpressSiteKeyStore::findByOsmUserId($osmUserId) : null;
        $wpFlash = $_SESSION['wp_waiting_flash'] ?? null;
        unset($_SESSION['wp_waiting_flash']);

        $sectionId = is_array($wpRow) ? (string) ($wpRow['section_id'] ?? '') : '';
        $sectionName = is_array($wpRow) ? (string) ($wpRow['section_name'] ?? '') : '';
        foreach ($waitingLists as $l) {
            if ($l['id'] === $sectionId && $sectionName === '') {
                $sectionName = (string) $l['name'];
            }
        }
        $notesLabel = '';
        if ($sectionId !== '') {
            try {
                $map = WaitingFieldMapStore::findBySection($sectionId);
            } catch (Throwable) {
                $map = null;
            }
            if (is_array($map) && ($map['notes_column_id'] ?? '') !== '') {
                $notesLabel = (string) (($map['notes_label'] ?? '') !== '' ? $map['notes_label'] : ($map['notes_varname'] ?? 'Notes'));
            }
        }

        App::render('wordpress-form.twig', Auth::baseContext([
            'title' => 'WordPress joining form',
            'waitingLists' => $waitingLists,
            'wpSiteKey' => is_array($wpRow) ? (string) ($wpRow['site_key'] ?? '') : '',
            'wpSectionId' => $sectionId,
            'wpSectionName' => $sectionName,
            'wpBlocked' => is_array($wpRow) && !empty($wpRow['blocked_at']),
            'wpFlash' => is_array($wpFlash) ? $wpFlash : null,
            'wpEndpoint' => self::ENDPOINT,
            'wpBaseUrl' => self::BASE_URL,
            'notesLabel' => $notesLabel,
            'plugin' => self::pluginInfo(),
        ]));
    }

    /** Streams the plugin zip to a signed-in leader. */
    public function download(): void
    {
        Auth::requireLogin();
        $file = self::zipPath();
        if (!is_file($file) || !is_readable($file)) {
            http_response_code(404);
            App::render('error.twig', [
                'title' => 'Not found',
                'message' => 'The WordPress plugin download is not available right now. Email hello@jonbloor.co.uk.',
            ]);
            return;
        }
        $info = self::pluginInfo();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $info['filename'] . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: private, no-store');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return; // The router discards any body for HEAD.
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($file);
    }

    /**
     * Save section / create or regenerate key / disable / clear block. Also used by the old
     * /settings/update-wordpress-waiting-list route so existing forms and bookmarks keep working.
     */
    public function save(): void
    {
        $token = Auth::requireLogin();
        Csrf::requireValid();
        $osmUserId = (string) ($_SESSION['osmUserId'] ?? '');
        if ($osmUserId === '') {
            self::back('error', 'Could not identify your OSM user. Sign out and sign in again.');
        }

        $action = (string) ($_POST['wp_action'] ?? 'save');
        if ($action === 'disable') {
            WordpressSiteKeyStore::deleteForUser($osmUserId);
            self::back('success', 'WordPress form site key removed. The form on your website will stop working until you create a new key.');
        }
        if ($action === 'clear_block') {
            $row = WordpressSiteKeyStore::findByOsmUserId($osmUserId);
            if ($row !== null) {
                WordpressSiteKeyStore::clearBlocked((int) $row['id']);
            }
            self::back('success', 'OSM block cleared. Fix the cause before accepting new form submissions.');
        }

        $sectionId = trim((string) ($_POST['waiting_list_section_id'] ?? ''));
        if ($sectionId === '' || !ctype_digit($sectionId)) {
            self::back('error', 'Choose a waiting-list section.');
        }

        $api = new OsmApi();
        try {
            $sections = $api->getDynamicSections($token);
        } catch (Throwable) {
            $sections = [];
        }
        $lists = WL::discoverLists($sections)['lists'];
        $sectionName = '';
        $allowed = false;
        foreach ($lists as $list) {
            if ($list['id'] === $sectionId) {
                $allowed = true;
                $sectionName = (string) ($list['name'] ?? '');
                break;
            }
        }
        if (!$allowed) {
            self::back('error', 'That waiting list is not one this login can see.');
        }

        $access = (string) ($_SESSION['accessToken'] ?? '');
        if ($access === '') {
            self::back('error', 'Missing OSM access token. Sign in again.');
        }
        $refresh = isset($_SESSION['refreshToken']) && is_string($_SESSION['refreshToken']) ? $_SESSION['refreshToken'] : null;
        $expires = isset($_SESSION['accessTokenExpiresAt']) && is_numeric($_SESSION['accessTokenExpiresAt'])
            ? (int) $_SESSION['accessTokenExpiresAt']
            : null;
        $regenerate = $action === 'regenerate' || !empty($_POST['regenerate_key']);

        try {
            $saved = WordpressSiteKeyStore::upsertForUser(
                $osmUserId,
                $sectionId,
                $sectionName,
                $access,
                $refresh,
                $expires,
                $regenerate
            );
        } catch (Throwable $e) {
            error_log('OSMHelper WordPress site key save failed: ' . $e->getMessage());
            self::back('error', 'Could not save the WordPress site key.');
        }

        self::back('success', $saved['regenerated']
            ? 'Site key created. Copy it into the WordPress plugin settings now. Any old key no longer works.'
            : 'Saved. Your OSM sign-in for the form has been refreshed.');
    }

    /**
     * Plugin zip details for the page (from downloads/osm-for-wordpress.json, written by the refresh script).
     * @return array{available: bool, filename: string, version: string, commit: string, builtAt: string, builtAtText: string, size: int, sizeText: string, sha256: string}
     */
    public static function pluginInfo(?string $dir = null): array
    {
        $dir = $dir ?? self::downloadsDir();
        $zip = $dir . '/osm-for-wordpress.zip';
        $manifest = [];
        $json = $dir . '/osm-for-wordpress.json';
        if (is_readable($json)) {
            $decoded = json_decode((string) file_get_contents($json), true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }
        $available = is_file($zip) && is_readable($zip);
        $size = $available ? (int) filesize($zip) : 0;
        $builtAt = (string) ($manifest['built_at'] ?? '');
        $builtAtText = '';
        if ($builtAt !== '') {
            try {
                $dt = new \DateTimeImmutable($builtAt);
                $builtAtText = $dt->setTimezone(new \DateTimeZone('Europe/London'))->format('j M Y');
            } catch (Throwable) {
                $builtAtText = '';
            }
        }
        $filename = (string) ($manifest['filename'] ?? 'osm-for-wordpress.zip');
        if (!preg_match('/^[A-Za-z0-9._-]+\.zip$/', $filename)) {
            $filename = 'osm-for-wordpress.zip';
        }
        return [
            'available' => $available,
            'filename' => $filename,
            'version' => (string) ($manifest['version'] ?? ''),
            'commit' => (string) ($manifest['commit'] ?? ''),
            'builtAt' => $builtAt,
            'builtAtText' => $builtAtText,
            'size' => $size,
            'sizeText' => $size > 0 ? (string) max(1, (int) round($size / 1024)) . ' KB' : '',
            'sha256' => (string) ($manifest['sha256'] ?? ''),
        ];
    }

    private static function downloadsDir(): string
    {
        return dirname(__DIR__, 3) . '/downloads';
    }

    private static function zipPath(): string
    {
        return self::downloadsDir() . '/osm-for-wordpress.zip';
    }

    private static function back(string $type, string $message): never
    {
        $_SESSION['wp_waiting_flash'] = ['type' => $type, 'message' => $message];
        header('Location: ' . self::PAGE);
        exit;
    }
}
