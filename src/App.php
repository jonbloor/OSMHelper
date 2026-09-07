<?php
declare(strict_types=1);
namespace App;
use App\Http\Auth;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankTransfersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MembersController;
use App\Http\Controllers\MembershipDashboardController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TopAwardsController;
use App\Http\Controllers\WaitingListController;
use App\Http\Router;
use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
final class App
{
    private static ?Environment $twig = null;
    private string $root;
    public function __construct(string $root) { $this->root = rtrim($root, '/'); }
    public function run(): void
    {
        $this->loadEnv();
        $this->bootSession();
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
        $bank = new BankTransfersController();
        $set = new SettingsController();
        $help = new HelpController();
        $roadmap = new RoadmapController();
        $topAwards = new TopAwardsController();

        $router->get('/', [$home, 'index']);
        $router->get('/auth', [$auth, 'redirectToOsm']);
        $router->get('/callback', [$auth, 'callback']);
        $router->get('/dashboard', [$dash, 'index']);
        $router->get('/logout', [$auth, 'logout']);
        $router->get('/membership-dashboard', [$md, 'index']);
        $router->get('/equipment', [$eq, 'index']);
        $router->post('/equipment/move', [$eq, 'move']);
        $router->get('/waiting-list', [$wl, 'index']);
        $router->get('/members', [$mem, 'index']);
        $router->get('/member-checks', [$mem, 'checks']);
        $router->get('/bank-transfers', [$bank, 'index']);
        $router->get('/bank-transfers/all', [$bank, 'allTransfers']);
        $router->post('/bank-transfers/select', [$bank, 'select']);
        $router->get('/settings', [$set, 'index']);
        $router->get('/help', [$help, 'index']);
        $router->get('/roadmap', [$roadmap, 'index']);
        $router->get('/top-awards', [$topAwards, 'index']);
        $router->post('/top-awards/select', [$topAwards, 'select']);
        $router->post('/top-awards/review', [$topAwards, 'review']);
        $router->post('/top-awards/apply', [$topAwards, 'apply']);
        $router->post('/settings/update-cutoffs', [$set, 'updateCutoffs']);
        $router->post('/settings/update-sections', [$set, 'updateSections']);
        $router->post('/settings/update-tool-sections', [$set, 'updateToolSections']);
        $router->post('/settings/update-equipment-locations', [$set, 'updateEquipmentLocations']);

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
            'lifetime' => 86400,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
        if (!isset($_SESSION['_init'])) $_SESSION['_init'] = hash('sha256', $secret);
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

    private function bootTwig(): void
    {
        self::$twig = new Environment(new FilesystemLoader($this->root . '/templates'), [
            'cache' => false, 'debug' => Config::debug(), 'strict_variables' => false,
        ]);
    }
}
