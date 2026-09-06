<?php
declare(strict_types=1);
namespace App\Osm;
use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
/**
 * Bearer-authenticated OSM API client with rate-limit header tracking.
 */
final class OsmApi
{
    private Client $http;
    /** @var array{limit?: int|null, remaining?: int|null, resetInSec?: int|null, lastUpdatedMs?: int} */
    private array $lastRateLimit = [];

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim(Config::osmApiBase(), '/') . '/',
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function get(string $accessToken, string $path, array $query = []): array
    {
        // Match Node: query string on the path (OSM is picky with some ext endpoints)
        if ($query !== []) {
            $sep = str_contains($path, '?') ? '&' : '?';
            $path .= $sep . http_build_query($query);
        }
        return $this->request('GET', $accessToken, $path, []);
    }

    /** @param array<string, mixed> $form @return array<string, mixed> */
    public function post(string $accessToken, string $path, array $form = []): array
    {
        return $this->request('POST', $accessToken, $path, ['form_params' => $form]);
    }

    /**
     * Snapshot from this request plus session (Node getRateLimitSnapshot parity).
     * @return array{limit: int|null, remaining: int|null, resetInSec: int|null, secondsUntilReset: int|null}|null
     */
    public function getRateLimitSnapshot(): ?array
    {
        return self::sessionSnapshot();
    }

    /**
     * @return array{limit: int|null, remaining: int|null, resetInSec: int|null, secondsUntilReset: int|null}|null
     */
    public static function sessionSnapshot(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $st = $_SESSION['osmRateLimit'] ?? null;
        if (!is_array($st)) {
            return null;
        }
        $resetInSec = isset($st['resetInSec']) && is_numeric($st['resetInSec']) ? (int) $st['resetInSec'] : null;
        $lastMs = isset($st['lastUpdatedMs']) && is_numeric($st['lastUpdatedMs']) ? (int) $st['lastUpdatedMs'] : null;
        $until = null;
        if ($resetInSec !== null && $lastMs !== null) {
            $elapsedSec = (int) floor(((int) round(microtime(true) * 1000) - $lastMs) / 1000);
            $until = max(0, $resetInSec - $elapsedSec);
        }
        $limit = isset($st['limit']) && is_numeric($st['limit']) ? (int) $st['limit'] : null;
        $remaining = isset($st['remaining']) && is_numeric($st['remaining']) ? (int) $st['remaining'] : null;
        if ($limit === null && $remaining === null && $resetInSec === null) {
            return null;
        }
        return [
            'limit' => $limit,
            'remaining' => $remaining,
            'resetInSec' => $resetInSec,
            'secondsUntilReset' => $until,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getDynamicSections(string $accessToken): array
    {
        $response = $this->get($accessToken, '/oauth/resource');
        $raw = $response['sections']
            ?? ($response['data']['sections'] ?? null)
            ?? $response['roles']
            ?? ($response['data']['roles'] ?? null)
            ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $now = time();
        $sections = [];
        foreach ($raw as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $currentTermId = $sec['current_term_id'] ?? -1;
            $terms = $sec['terms'] ?? null;
            if (is_array($terms)) {
                $matched = null;
                foreach ($terms as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $start = isset($t['startdate']) ? strtotime((string) $t['startdate']) : false;
                    $end = isset($t['enddate']) ? strtotime((string) $t['enddate']) : false;
                    if ($start !== false && $end !== false && $start <= $now && $end >= $now) {
                        $matched = $t['term_id'] ?? null;
                        break;
                    }
                }
                if ($matched !== null) {
                    $currentTermId = $matched;
                } elseif ($terms !== []) {
                    $last = $terms[array_key_last($terms)];
                    if (is_array($last) && isset($last['term_id'])) {
                        $currentTermId = $last['term_id'];
                    }
                }
            }
            $sec['current_term_id'] = $currentTermId;
            $sections[] = $sec;
        }
        return $sections;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function request(string $method, string $accessToken, string $path, array $options = []): array
    {
        $path = ltrim($path, '/');
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ]);
        $response = $this->http->request($method, $path, $options);
        $this->captureRateLimit($response->getHeaders());
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status >= 400) {
            throw new \RuntimeException('OSM HTTP ' . $status . ' for /' . $path, $status);
        }
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['_raw' => $body];
        }
        if (isset($decoded['data']) && is_string($decoded['data'])) {
            $inner = json_decode($decoded['data'], true);
            if (is_array($inner)) {
                $decoded['data'] = $inner;
            }
        }
        return $decoded;
    }

    /** @param array<string, list<string>> $headers */
    private function captureRateLimit(array $headers): void
    {
        $pick = static function (array $headers, string ...$names): ?string {
            foreach ($names as $name) {
                foreach ($headers as $key => $values) {
                    if (strcasecmp((string) $key, $name) === 0 && isset($values[0])) {
                        return (string) $values[0];
                    }
                }
            }
            return null;
        };
        $limit = $pick($headers, 'X-RateLimit-Limit', 'X-Ratelimit-Limit', 'x-ratelimit-limit');
        $remaining = $pick($headers, 'X-RateLimit-Remaining', 'X-Ratelimit-Remaining', 'x-ratelimit-remaining');
        $reset = $pick($headers, 'X-RateLimit-Reset', 'X-Ratelimit-Reset', 'x-ratelimit-reset');
        if ($limit === null && $remaining === null && $reset === null) {
            return;
        }
        $state = [
            'limit' => $limit !== null && is_numeric($limit) ? (int) $limit : null,
            'remaining' => $remaining !== null && is_numeric($remaining) ? (int) $remaining : null,
            'resetInSec' => $reset !== null && is_numeric($reset) ? (int) $reset : null,
            'lastUpdatedMs' => (int) round(microtime(true) * 1000),
        ];
        $this->lastRateLimit = $state;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['osmRateLimit'] = $state;
        }
    }
}
