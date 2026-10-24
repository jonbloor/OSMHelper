<?php
declare(strict_types=1);
namespace App;
use App\Http\Auth;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankTransfersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\FindMemberController;
use App\Http\Controllers\YoungLeadersController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MembersController;
use App\Http\Controllers\MembershipDashboardController;
use App\Http\Controllers\NightsAwayController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TopAwardsController;
use App\Http\Controllers\WaitingListController;
use App\Http\Controllers\WordpressFormController;
use App\Http\Controllers\Api\WaitingListSubmitController;
use App\Http\Router;
use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
final class App
{
    private static ?Environment $twig = null;
    private string $root;
    public function __construct(string $root) { $this->root = rtrim($root, '/'); }
    public function run(): void
    {
        $this->loadEnv();
        $this->bootSession();
        // Delete expired private caches on every request (glob + mtime, cheap). See sweepPrivateCaches().
        self::sweepPrivateCaches();
        // Error log entries are kept for at most 30 days (reads one line when nothing is due).
        \App\Osm\OsmErrorLog::pruneOld();
        self::sendSecurityHeaders();
        self::upgradeInsecureOAuthCallback();
        $this->bootTwig();
        $router = new Router();
        $home = new HomeController();
        $auth = new AuthController();
        $dash = new DashboardController();
        $md = new MembershipDashboardController();
        $eq = new EquipmentController();
        $wl = new WaitingListController();
        $mem = new MembersController();
        $find = new FindMemberController();
        $yl = new YoungLeadersController();
        $events = new EventsController();
        $bank = new BankTransfersController();
        $set = new SettingsController();
        $help = new HelpController();
        $roadmap = new RoadmapController();
        $changelog = new ChangelogController();
        $topAwards = new TopAwardsController();
        $nightsAway = new NightsAwayController();
        $wlApi = new WaitingListSubmitController();
        $wpForm = new WordpressFormController();

        $router->get('/', [$home, 'index']);
        $router->get('/auth', [$auth, 'redirectToOsm']);
        $router->get('/callback', [$auth, 'callback']);
        $router->get('/dashboard', [$dash, 'index']);
        $router->get('/logout', [$auth, 'logout']);
        $router->get('/membership-dashboard', [$md, 'index']);
        $router->post('/membership-dashboard', [$md, 'run']);
        $router->get('/equipment', [$eq, 'index']);
        $router->post('/equipment/move', [$eq, 'move']);
        $router->post('/equipment/add', [$eq, 'add']);
        $router->get('/waiting-list', [$wl, 'index']);
        $router->get('/waiting-list/fields', [$wl, 'fields']);
        $router->post('/waiting-list/fields', [$wl, 'saveFields']);
        $router->get('/waiting-list/rank', [$wl, 'rank']);
        $router->post('/waiting-list/review', [$wl, 'review']);
        $router->post('/waiting-list/apply', [$wl, 'apply']);
        $router->post('/api/waiting-list/submit', [$wlApi, 'submit']);
        $router->get('/wordpress-form', [$wpForm, 'index']);
        $router->get('/wordpress-form/download', [$wpForm, 'download']);
        $router->post('/wordpress-form/save', [$wpForm, 'save']);
        $router->get('/members', [$mem, 'index']);
        $router->post('/members', [$mem, 'run']);
        $router->get('/member-checks', [$mem, 'checks']);
        $router->get('/find-member', [$find, 'index']);
        $router->post('/find-member/search', [$find, 'search']);
        $router->post('/find-member/refresh', [$find, 'refresh']);
        $router->get('/young-leaders', [$yl, 'index']);
        $router->post('/young-leaders/load', [$yl, 'load']);
        $router->post('/young-leaders/refresh', [$yl, 'refresh']);
        $router->get('/events', [$events, 'index']);
        $router->post('/events/refresh', [$events, 'refresh']);
        $router->get('/events/csv', [$events, 'csv']);
        $router->get('/nights-away', [$nightsAway, 'index']);
        $router->post('/nights-away/select', [$nightsAway, 'select']);
        $router->get('/bank-transfers', [$bank, 'index']);
        $router->get('/bank-transfers/all', [$bank, 'allTransfers']);
        $router->post('/bank-transfers/select', [$bank, 'select']);
        $router->get('/settings', [$set, 'index']);
        $router->get('/help', [$help, 'index']);
        $router->get('/roadmap', [$roadmap, 'index']);
        $router->get('/changelog', [$changelog, 'index']);
        $router->get('/top-awards', [$topAwards, 'index']);
        $router->post('/top-awards/select', [$topAwards, 'select']);
        $router->post('/top-awards/review', [$topAwards, 'review']);
        $router->post('/top-awards/apply', [$topAwards, 'apply']);
        $router->post('/settings/update-cutoffs', [$set, 'updateCutoffs']);
        $router->post('/settings/update-sections', [$set, 'updateSections']);
        $router->post('/settings/update-tool-sections', [$set, 'updateToolSections']);
        $router->post('/settings/update-equipment-locations', [$set, 'updateEquipmentLocations']);
        // Old form target (before the WordPress form page existed); same handler, redirects to /wordpress-form/.
        $router->post('/settings/update-wordpress-waiting-list', [$wpForm, 'save']);

        $router->setNotFound(static function (): void {
            http_response_code(404);
            self::render('error.twig', ['title' => 'Not found', 'message' => 'Page not found.', 'authed' => self::isAuthenticated()]);
        });
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['OSMHELPER_PATH'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $router->dispatch($method, $uri);
    }
    public static function render(string $template, array $context = []): void
    {
        if (self::$twig === null) throw new \RuntimeException('Twig not initialised');
        if (!isset($context['authed'])) $context['authed'] = self::isAuthenticated();
        if (!isset($context['csrfToken'])) {
            $context['csrfToken'] = \App\Http\Csrf::token();
        }
        if (!empty($context['authed']) && !array_key_exists('sessionEndsText', $context)) {
            $ends = self::sessionEndsAt();
            $context['sessionEndsText'] = $ends === null ? null
                : (new \DateTimeImmutable('@' . $ends))->setTimezone(new \DateTimeZone('Europe/London'))->format('H:i');
            $context['sessionLengthText'] = self::durationText(Config::sessionAbsoluteSeconds());
        }
        if (!empty($context['authed']) && !array_key_exists('rateLimit', $context)) {
            $context = array_merge(Auth::rateLimitContext(), $context);
        }
        echo self::$twig->render($template, $context);
    }
    public static function isAuthenticated(): bool { return !empty($_SESSION['accessToken']); }
    private function loadEnv(): void
    {
        if (is_readable($this->root . '/.env')) {
            Dotenv::createImmutable($this->root)->safeLoad();
        }
    }
    private function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secret = Config::get('SESSION_SECRET', 'dev-insecure-change-me') ?? 'dev-insecure-change-me';
        session_name('osmhelper');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
        // Production host is always HTTPS; never emit a non-Secure session cookie there
        // (an http:// intermediate redirect would otherwise start a second empty session).
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (str_contains($host, 'osmhelper.co.uk')) {
            $https = true;
        }
        session_set_cookie_params([
            // Never shorter than the in-app limit (both default to 2 hours; see Config::sessionAbsoluteSeconds()).
            'lifetime' => self::sessionLifetimeSeconds(),
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        // Sessions hold the OSM token and short-lived member caches: keep them for 2 hours at most.
        // Files live in the app's own storage/sessions (0700, outside the docroot) so PHP's own
        // garbage collection with our 2-hour limit applies (the system-wide cleaner uses 24 hours).
        self::useAppSessionDir($this->root . '/storage/sessions');
        ini_set('session.gc_maxlifetime', (string) self::sessionLifetimeSeconds());
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '50');
        session_start();
        self::enforceSessionMaxAge();
        if (!isset($_SESSION['_init'])) $_SESSION['_init'] = hash('sha256', $secret);
    }

    /** Default session limit (2 hours); the live values come from Config (SESSION_ABSOLUTE_SECONDS / SESSION_IDLE_SECONDS). */
    public const SESSION_MAX_AGE_SEC = Config::SESSION_ABSOLUTE_DEFAULT;

    /** Cookie lifetime and file GC age: at least as long as the longest in-app limit. */
    public static function sessionLifetimeSeconds(): int
    {
        return max(Config::sessionAbsoluteSeconds(), Config::sessionIdleSeconds());
    }

    private static function useAppSessionDir(string $dir): void
    {
        $override = Config::get('OSMHELPER_SESSION_PATH');
        if ($override !== null && $override !== '') {
            $dir = $override;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            // Don't break the site: fall back to PHP's default save path, and say why (no paths/IDs).
            error_log('OSMHelper sessions: session_dir_create_failed (using PHP default)');
            return;
        }
        if ((fileperms($dir) & 0777) !== 0700) {
            @chmod($dir, 0700);
        }
        if (!is_writable($dir)) {
            error_log('OSMHelper sessions: session_dir_not_writable (using PHP default)');
            return;
        }
        session_save_path($dir);
    }

    /**
     * Absolute + idle limit enforced in the app (the cookie and file GC are only best effort).
     * Defaults: 2 hours after sign-in, even while in use (Config::sessionAbsoluteSeconds()), or 2 hours idle.
     * An expired session is emptied (Top awards caches cleared too) and given a new ID.
     */
    public static function enforceSessionMaxAge(?int $now = null): bool
    {
        $now ??= time();
        $started = $_SESSION['_startedAt'] ?? null;
        $seen = $_SESSION['_lastSeen'] ?? null;
        $expired = (is_int($started) && $now - $started >= Config::sessionAbsoluteSeconds())
            || (is_int($seen) && $now - $seen >= Config::sessionIdleSeconds());
        if ($expired) {
            TopAwardsController::clearSessionCaches();
            \App\Osm\OsmCache::forgetCurrentUser(); // cached OSM data (waiting list, sections) for this user
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
        }
        if (!is_int($_SESSION['_startedAt'] ?? null)) {
            $_SESSION['_startedAt'] = $now;
        }
        $_SESSION['_lastSeen'] = $now;
        return $expired;
    }

    /**
     * Call after a successful sign-in: new session ID (stops session fixation, and re-sends the cookie
     * so its lifetime counts from sign-in) and the session clock restarts.
     */
    public static function beginAuthenticatedSession(?int $now = null): void
    {
        $now ??= time();
        // Start clean: nothing from before sign-in (or from a previous leader on this browser) carries over,
        // except the page to return to.
        $returnTo = $_SESSION['returnTo'] ?? null;
        TopAwardsController::clearSessionCaches();
        \App\Osm\OsmCache::forgetCurrentUser(); // cached OSM data (waiting list, sections) for this user
        $_SESSION = [];
        if ($returnTo !== null) {
            $_SESSION['returnTo'] = $returnTo;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_startedAt'] = $now;
        $_SESSION['_lastSeen'] = $now;
        // Signed in: the sign-in loop guard starts afresh (already gone with the wipe above; explicit for clarity).
        \App\Http\SignInGuard::clear($_SESSION);
    }

    /** Unix time this session will end (absolute or idle limit, whichever is sooner), or null if not signed in. */
    /** "2 hours", "1 hour", "90 minutes": for the configured session length in page text. */
    public static function durationText(int $seconds): string
    {
        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            $h = intdiv($seconds, 3600);
            return $h . ($h === 1 ? ' hour' : ' hours');
        }
        $m = max(1, intdiv($seconds + 59, 60));
        return $m . ($m === 1 ? ' minute' : ' minutes');
    }

    public static function sessionEndsAt(): ?int
    {
        if (!self::isAuthenticated() || !is_int($_SESSION['_startedAt'] ?? null)) {
            return null;
        }
        $end = $_SESSION['_startedAt'] + Config::sessionAbsoluteSeconds();
        if (is_int($_SESSION['_lastSeen'] ?? null)) {
            $end = min($end, $_SESSION['_lastSeen'] + Config::sessionIdleSeconds());
        }
        return $end;
    }

    /**
     * Same-site path to go back to after signing in again, or null. Only plain paths ("/members/?x=1"):
     * no scheme, host, "//" or "\\" tricks, control characters, or the sign-in/out pages themselves.
     */
    public static function safeReturnPath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || strlen($path) > 512 || $path[0] !== '/') {
            return null;
        }
        if (str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }
        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || !isset($parts['path'])) {
            return null;
        }
        $first = strtolower(explode('/', trim($parts['path'], '/'))[0]);
        if (in_array($first, ['auth', 'callback', 'logout', 'login'], true)) {
            return null;
        }
        return $path;
    }

    /** Remember the current GET page so sign-in can bring the leader back to it. */
    public static function rememberReturnPath(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        $path = self::safeReturnPath($_SERVER['REQUEST_URI'] ?? null);
        if ($path !== null && $path !== '/') {
            $_SESSION['returnTo'] = $path;
        }
    }

    /** The remembered page (validated again) and forget it; "/" when there is none. */
    public static function takeReturnPath(): string
    {
        $path = self::safeReturnPath($_SESSION['returnTo'] ?? null);
        unset($_SESSION['returnTo']);
        return $path ?? '/';
    }

    /**
     * CloudPanel may 301 /callback → http://…/callback/ which drops the Secure
     * session cookie. Bounce to HTTPS before fail-closed state check when possible.
     */
    private static function upgradeInsecureOAuthCallback(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        if ($https) {
            return;
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pathNorm = '/' . trim((string) $path, '/');
        if ($pathNorm !== '/callback') {
            return;
        }
        // Local development (php -S on localhost) keeps its own http:// callback.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host)) {
            return;
        }
        if (!isset($_GET['code'], $_GET['state'])) {
            return;
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $target = 'https://osmhelper.co.uk/callback' . ($qs !== '' ? ('?' . $qs) : '');
        header('Location: ' . $target, true, 302);
        exit;
    }

    private static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');
        // Modest CSP: allow self + existing inline layout/table-tools bootstrap scripts
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
    }

    /**
     * Delete expired caches that hold member data, on every request, so the privacy promises hold
     * even when nobody opens the page that wrote them:
     *  - Top awards caches (member names): 2-hour TTL.
     *  - Young Leaders copies (ylsection/ylmember: names, ages, section names): 2-hour TTL.
     *  - OSM read cache (storage/osm-cache: waiting-list applicants' names, dates of birth, dates
     *    joined, every waiting-list custom field and leaders' notes; section lists): 5 minutes.
     */
    public static function sweepPrivateCaches(): void
    {
        TopAwardsController::sweepExpiredCaches();
        \App\Osm\OsmCache::sweep();
        \App\Waiting\IntakeThrottle::sweep();
        \App\Members\YoungLeaderLimits::sweep(); // at most hourly: users whose limits have all expired
    }

    private function bootTwig(): void
    {
        self::$twig = new Environment(new FilesystemLoader($this->root . '/templates'), [
            'cache' => false, 'debug' => Config::debug(), 'strict_variables' => false,
        ]);
        $root = $this->root;
        self::$twig->addFunction(new TwigFunction('asset', static function (string $path) use ($root): string {
            $path = ltrim($path, '/');
            if (!str_starts_with($path, 'assets/')) {
                $path = 'assets/' . $path;
            }
            $file = $root . '/public/' . $path;
            $v = is_readable($file) ? (string) filemtime($file) : '1';
            return '/' . $path . '?v=' . rawurlencode($v);
        }));
        self::$twig->addFunction(new TwigFunction('main_nav', static function (bool $authed): array {
            return \App\Http\Nav::forRequest($authed);
        }));
    }
}
