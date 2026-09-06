<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    /** @var null|callable */
    private $notFound = null;

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$this->normalize($path)] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$this->normalize($path)] = $handler;
    }

    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->normalize($path);
        $method = strtoupper($method);
        // Treat HEAD like GET but discard the body (soft fix for uptime probes).
        $isHead = $method === 'HEAD';
        if ($isHead) {
            $method = 'GET';
        }

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler !== null) {
            if ($isHead) {
                ob_start();
                $handler();
                ob_end_clean();
                return;
            }
            $handler();
            return;
        }

        if ($this->notFound !== null) {
            if ($isHead) {
                ob_start();
                ($this->notFound)();
                ob_end_clean();
                return;
            }
            ($this->notFound)();
            return;
        }

        http_response_code(404);
        if (!$isHead) {
            echo 'Not Found';
        }
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
