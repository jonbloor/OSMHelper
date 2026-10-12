<?php
declare(strict_types=1);
/**
 * Offline tests for the WordPress joining form page (no network / no OSM).
 * Run: php tests/wordpress-form-test.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Http\Controllers\WordpressFormController;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  $msg\n"; } else { $failed++; echo "FAIL $msg\n"; }
}

$root = dirname(__DIR__);

// ---- Plugin zip + manifest shipped in downloads/
$info = WordpressFormController::pluginInfo();
check($info['available'] === true, 'plugin zip is present in downloads/');
check($info['filename'] === 'osm-for-wordpress.zip', 'stable download filename');
check($info['version'] !== '', 'manifest has a version (' . $info['version'] . ')');
check($info['commit'] !== '' && $info['builtAtText'] !== '', 'manifest has commit and build date');
$zipFile = $root . '/downloads/osm-for-wordpress.zip';
check(hash_file('sha256', $zipFile) === $info['sha256'], 'manifest sha256 matches the zip');
$zip = new ZipArchive();
check($zip->open($zipFile) === true, 'zip opens');
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = (string) $zip->getNameIndex($i); }
check(in_array('osm-for-wordpress/osm-for-wordpress.php', $names, true), 'zip has osm-for-wordpress/osm-for-wordpress.php');
check(array_filter($names, static fn ($n) => !str_starts_with($n, 'osm-for-wordpress/')) === [], 'everything sits inside osm-for-wordpress/');
check(array_filter($names, static fn ($n) => str_contains($n, 'node_modules') || str_contains($n, '/.git') || str_contains($n, '/tests/')) === [], 'no node_modules, .git or tests in the zip');
$header = (string) $zip->getFromName('osm-for-wordpress/osm-for-wordpress.php');
check(str_contains($header, 'Version: ' . $info['version']), 'manifest version matches the plugin header');
check(in_array('osm-for-wordpress/includes/class-osm-waiting-list.php', $names, true), 'zip includes the waiting-list form');
$zip->close();
check(!is_file($root . '/public/osm-for-wordpress.zip') && !is_dir($root . '/public/downloads'), 'zip is not under public/ (served only via the signed-in route)');

// Missing / odd manifest
$tmp = sys_get_temp_dir() . '/wpf-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$none = WordpressFormController::pluginInfo($tmp);
check($none['available'] === false && $none['version'] === '' && $none['sizeText'] === '', 'no zip → not available, no version');
file_put_contents($tmp . '/osm-for-wordpress.zip', 'x');
file_put_contents($tmp . '/osm-for-wordpress.json', json_encode(['filename' => '../../etc/passwd', 'version' => '9.9', 'built_at' => '2026-10-05T23:30:00Z']));
$odd = WordpressFormController::pluginInfo($tmp);
check($odd['filename'] === 'osm-for-wordpress.zip', 'unsafe filename in manifest is ignored');
check($odd['builtAtText'] === '6 Oct 2026', 'build date shown in UK time');
check($odd['available'] === true && $odd['sizeText'] === '1 KB', 'tiny zip still shows a size');
array_map('unlink', glob($tmp . '/*'));
rmdir($tmp);

// ---- Routes + stubs
$app = (string) file_get_contents($root . '/src/App.php');
foreach (["get('/wordpress-form'", "get('/wordpress-form/download'", "post('/wordpress-form/save'", "post('/settings/update-wordpress-waiting-list', [\$wpForm, 'save'])"] as $r) {
    check(str_contains($app, $r), "route registered: $r");
}
foreach (['wordpress-form', 'wordpress-form/download', 'wordpress-form/save', 'settings/update-wordpress-waiting-list'] as $stub) {
    $f = $root . '/deploy/route-stubs/' . $stub . '/index.php';
    check(is_file($f) && str_contains((string) file_get_contents($f), "'/" . $stub . "'"), "route stub: /$stub/");
}
$ctrl = (string) file_get_contents($root . '/src/Http/Controllers/WordpressFormController.php');
foreach (['index', 'download', 'save'] as $m) {
    check((bool) preg_match('/function ' . $m . '\(\): void\s*\{\s*(\$token = )?Auth::requireLogin\(\);/', $ctrl), "$m() requires sign-in first");
}
check(str_contains($ctrl, 'Csrf::requireValid();'), 'save() checks CSRF');

// ---- download() streams the exact zip to a signed-in leader (run in a child process: it clears output buffers)
$code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; $_SESSION = ["accessToken" => "t"]; $_SERVER["REQUEST_METHOD"] = "GET"; (new App\\Http\\Controllers\\WordpressFormController())->download();';
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
check(is_string($out) && hash('sha256', $out) === hash_file('sha256', $zipFile), 'download() streams the zip byte for byte');
$code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; $_SESSION = ["accessToken" => "t"]; $_SERVER["REQUEST_METHOD"] = "HEAD"; (new App\\Http\\Controllers\\WordpressFormController())->download();';
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
check($out === null || $out === '', 'download() sends no body for HEAD');

// ---- Templates render
$twig = new Environment(new FilesystemLoader($root . '/templates'), ['strict_variables' => false]);
$twig->addFunction(new TwigFunction('asset', static fn (string $p): string => '/assets/' . ltrim($p, '/')));
$base = ['authed' => true, 'csrfToken' => 'tok', 'plugin' => $info, 'wpEndpoint' => WordpressFormController::ENDPOINT, 'wpBaseUrl' => WordpressFormController::BASE_URL,
    'waitingLists' => [['id' => '60830', 'name' => 'Waiting list', 'groupName' => '4th Ashby']]];

$fresh = $twig->render('wordpress-form.twig', $base + ['wpSiteKey' => '', 'wpSectionId' => '', 'notesLabel' => '']);
check(str_contains($fresh, 'href="/wordpress-form/download/"'), 'page: Download plugin button');
check(str_contains($fresh, 'Version ' . $info['version']), 'page: shows plugin version');
check(str_contains($fresh, '[osm_waiting_list]') && str_contains($fresh, 'Plugins → Add New → Upload Plugin') && str_contains($fresh, 'OSM Settings'), 'page: WordPress setup steps');
check(str_contains($fresh, '<code>https://osmhelper.co.uk</code>'), 'page: base URL to paste');
check(str_contains($fresh, 'action="/wordpress-form/save/"') && str_contains($fresh, 'name="waiting_list_section_id"'), 'page: section picker posts to /wordpress-form/save/');
check(str_contains($fresh, 'name="_csrf"') || str_contains($fresh, 'tok'), 'page: CSRF token included');
check(!str_contains($fresh, 'Clear the OSM block') && !str_contains($fresh, 'Turn off the form'), 'page (new): no block / turn-off buttons');
check(str_contains($fresh, 'Places API (New)') && str_contains($fresh, 'postcodes.io') && str_contains($fresh, 'reCAPTCHA') && str_contains($fresh, 'Reply-to'), 'page: options (address lookup, captcha, reply-to)');
check(str_contains($fresh, 'href="/waiting-list/fields/"'), 'page: link to Rank & notes settings');
check(str_contains($fresh, 'id="troubleshooting"') && str_contains($fresh, 'Invalid site key') && str_contains($fresh, 'OSM blocked this application'), 'page: troubleshooting');
check(str_contains($fresh, 'hello@jonbloor.co.uk'), 'page: contact email');

$set = $twig->render('wordpress-form.twig', $base + ['wpSiteKey' => 'KEY-abc', 'wpSectionId' => '60830', 'wpSectionName' => 'Waiting list', 'notesLabel' => 'HNotes', 'wpBlocked' => true,
    'wpFlash' => ['type' => 'success', 'message' => 'Saved.']]);
check(str_contains($set, 'value="KEY-abc"'), 'page (set up): shows site key to copy');
check(str_contains($set, 'Notes go into <strong>HNotes</strong>') && str_contains($set, '/waiting-list/fields/?section=60830'), 'page (set up): Notes field and section link');
check(str_contains($set, 'Clear the OSM block') && str_contains($set, 'Turn off the form') && str_contains($set, 'Save and make a new site key'), 'page (set up): block, turn-off and new-key buttons');
check(str_contains($set, 'selected') && str_contains($set, 'Saved.'), 'page (set up): section selected and flash shown');
$noNotes = $twig->render('wordpress-form.twig', $base + ['wpSiteKey' => 'K', 'wpSectionId' => '60830', 'notesLabel' => '']);
check(str_contains($noNotes, 'No Notes field is chosen'), 'page: warns when no Notes field');

$settings = $twig->render('settings.twig', ['authed' => true, 'csrfToken' => 't', 'displayCutoffs' => [], 'displaySections' => [], 'allSections' => [], 'equipmentLocations' => [], 'wpConfigured' => true, 'wpSectionName' => 'Waiting list']);
check(str_contains($settings, 'href="/wordpress-form/"') && !str_contains($settings, 'waiting_list_section_id'), 'settings: links to the new page, form moved');
check(str_contains($settings, 'Set up: Waiting list'), 'settings: shows set-up status');
check(str_contains($settings, '/settings/update-cutoffs/'), 'settings: other settings still there');

check(str_contains($twig->render('dashboard.twig', ['authed' => true]), 'href="/wordpress-form/">Joining form</a>'), 'menu: Joining form item for signed-in leaders');
check(!str_contains($twig->render('help.twig', ['authed' => false]), 'href="/wordpress-form/">Joining form</a>'), 'menu: not shown when signed out');

$help = $twig->render('help.twig', ['authed' => false]);
check(str_contains($help, 'id="wordpress-form"') && str_contains($help, '[osm_waiting_list]'), 'help: WordPress joining form section');
$road = $twig->render('roadmap.twig', ['authed' => false]);
check(str_contains($road, 'id="2026-10-05"') && str_contains($road, 'WordPress joining form'), 'roadmap/changelog: 5 Oct 2026 entry');
$home = $twig->render('home.twig', ['authed' => false]);
check(str_contains($home, 'id="home-f-wp"') && str_contains($home, 'text messages'), 'home: WordPress joining form feature');
check(str_contains($home, 'Keep the forms parents send.') && str_contains($home, 'unless you turn on the WordPress joining form'), 'home: privacy wording covers the form sign-in');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
