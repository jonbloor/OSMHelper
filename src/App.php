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

        $router->get('/', [$home, 'index']);
        $router->get('/auth', [$auth, 'redirectToOsm']);
        $router->get('/callback', [$auth, 'callback']);
        $router->get('/dashboard', [$dash, 'index']);
        $router->get('/logout', [$auth, 'logout']);
        $router->get('/membership-dashboard', [$md, 'index']);
        $router->get('/equipment', [$eq, 'index']);
        $router->get('/waiting-list', [$wl, 'index']);
        $router->get('/members', [$mem, 'index']);
        $router->get('/member-checks', [$mem, 'checks']);
        $router->get('/bank-transfers', [$bank, 'index']);
        $router->post('/bank-transfers/select', [$bank, 'select']);
        $router->get('/settings', [$set, 'index']);
        $router->get('/help', [$help, 'index']);
        $router->get('/roadmap', [$roadmap, 'index']);
        $router->post('/settings/update-cutoffs', [$set, 'updateCutoffs']);
        $router->post('/settings/update-sections', [$set, 'updateSections']);
        $router->post('/settings/update-tool-sections', [$set, 'updateToolSections']);

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
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params(['lifetime' => 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        session_start();
        if (!isset($_SESSION['_init'])) $_SESSION['_init'] = hash('sha256', $secret);
    }
    private function bootTwig(): void
    {
        self::$twig = new Environment(new FilesystemLoader($this->root . '/templates'), [
            'cache' => false, 'debug' => Config::debug(), 'strict_variables' => false,
        ]);
    }
}
