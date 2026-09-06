<?php

declare(strict_types=1);

namespace App;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Router;
use Dotenv\Dotenv;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class App
{
    private static ?Environment $twig = null;

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function run(): void
    {
        $this->loadEnv();
        $this->bootSession();
        $this->bootTwig();

        $router = new Router();
        $home = new HomeController();
        $auth = new AuthController();
        $dash = new DashboardController();

        $router->get('/', [$home, 'index']);
        $router->get('/auth', [$auth, 'redirectToOsm']);
        $router->get('/callback', [$auth, 'callback']);
        $router->get('/dashboard', [$dash, 'index']);
        $router->get('/logout', [$auth, 'logout']);

        $router->setNotFound(static function (): void {
            http_response_code(404);
            self::render('error.twig', [
                'title' => 'Not found',
                'message' => 'Page not found.',
            ]);
        });

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // OSMHELPER_PATH set by CloudPanel docroot route stubs when nginx has no front-controller rewrite
        $uri = $_SERVER['OSMHELPER_PATH'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $router->dispatch($method, $uri);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function render(string $template, array $context = []): void
    {
        if (self::$twig === null) {
            throw new \RuntimeException('Twig not initialised');
        }

        echo self::$twig->render($template, $context);
    }

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['accessToken']);
    }

    private function loadEnv(): void
    {
        $envFile = $this->root . '/.env';
        if (is_readable($envFile)) {
            $dotenv = Dotenv::createImmutable($this->root);
            $dotenv->safeLoad();
        }
    }

    private function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secret = Config::get('SESSION_SECRET', 'dev-insecure-change-me') ?? 'dev-insecure-change-me';

        session_name('osmhelper');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Bind session to a hash of the secret without exposing it
        ini_set('session.use_strict_mode', '1');
        session_start();

        if (!isset($_SESSION['_init'])) {
            $_SESSION['_init'] = hash('sha256', $secret);
        }
    }

    private function bootTwig(): void
    {
        $loader = new FilesystemLoader($this->root . '/templates');
        self::$twig = new Environment($loader, [
            'cache' => false,
            'debug' => Config::debug(),
            'strict_variables' => false,
        ]);
    }
}
