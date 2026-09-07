<?php
declare(strict_types=1);
namespace App\Osm;
use App\Config;
use GuzzleHttp\Client;
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
        // Prefer Guzzle query option (axios params parity). Also keep path clean with trailing slash.
        $path = '/' . ltrim($path, '/');
        $options = [];
        if ($query !== []) {
            // Node equipment embeds query on the path string; bank uses params.
            // Building both the same absolute query avoids ?/%3F quirks with base_uri.
            $sep = str_contains($path, '?') ? '&' : '?';
            $path .= $sep . http_build_query($query);
        }
        return $this->request('GET', $accessToken, $path, $options);
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
        // Absolute path from site root so base_uri host is used without eating query
        if (!preg_match('#^https?://#i', $path)) {
            $path = ltrim($path, '/');
        }
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];
        // form_params need application/x-www-form-urlencoded (Guzzle sets it).
        // Forcing JSON here breaks OSM quartermaster column updates.
        if (!isset($options['form_params'])) {
            $headers['Content-Type'] = 'application/json';
        }
        $options['headers'] = array_merge($options['headers'] ?? [], $headers);
        $response = $this->http->request($method, $path, $options);
        $this->captureRateLimit($response->getHeaders());
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status >= 400) {
            $errHint = '';
            $decodedErr = json_decode($body, true);
            if (is_array($decodedErr)) {
                $msg = $decodedErr['error']['message'] ?? ($decodedErr['error'] ?? null);
                $code = is_array($decodedErr['error'] ?? null) ? ($decodedErr['error']['code'] ?? null) : null;
                if (is_string($msg) && $msg !== '') {
                    $errHint = ' — ' . $msg . ($code ? " [{$code}]" : '');
                }
            }
            if ($status === 429) {
                $ra = $this->getRetryAfterSeconds();
                if ($ra !== null) {
                    $errHint .= ' (Retry-After ' . $ra . 's)';
                }
            }
            $pathHint = ltrim(explode('?', $path, 2)[0], '/');
            $msg = 'OSM HTTP ' . $status . ' for /' . $pathHint . (str_contains($path, '?') ? '?…' : '') . $errHint;
            // OSM actions are case-sensitive; invalid-action must fail fast (no alternate probes).
            $blob = strtolower($msg . ' ' . $body);
            if (str_contains($blob, 'invalid-action') || str_contains($blob, 'invalid action')) {
                $msg = 'OSM_INVALID_ACTION: ' . $msg;
            }
            throw new \RuntimeException($msg, $status);
        }
        return self::decodeBody($body);
    }

    /** @return array<string, mixed> */
    public static function decodeBody(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $trimmed = trim($body);
        // Some OSM endpoints historically returned JS assignments
        if (preg_match('/^(?:var\s+\w+\s*=\s*|window\.\w+\s*=\s*)/i', $trimmed)) {
            $trimmed = preg_replace('/^(?:var\s+\w+\s*=\s*|window\.\w+\s*=\s*)/i', '', $trimmed) ?? $trimmed;
            $trimmed = rtrim(trim($trimmed), ';');
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return ['_raw' => substr($body, 0, 500)];
        }
        // Unwrap stringy JSON under data (and one nested level)
        for ($i = 0; $i < 2; $i++) {
            if (!isset($decoded['data']) || !is_string($decoded['data'])) {
                break;
            }
            $inner = json_decode($decoded['data'], true);
            if (!is_array($inner)) {
                break;
            }
            $decoded['data'] = $inner;
        }
        return $decoded;
    }

    /** Seconds to wait after a 429, if OSM sent Retry-After. */
    public function getRetryAfterSeconds(): ?int
    {
        $v = $this->lastRateLimit['retryAfterSec'] ?? null;
        return is_int($v) ? $v : null;
    }

    /**
     * True when remaining quota is too low for another fan-out burst.
     * Threshold is deliberately conservative for badge-heavy tools.
     */
    public function isRateLow(int $minRemaining = 25): bool
    {
        $snap = $this->getRateLimitSnapshot();
        if ($snap === null || $snap['remaining'] === null) {
            return false;
        }
        return $snap['remaining'] <= $minRemaining;
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
        $retryAfter = $pick($headers, 'Retry-After', 'retry-after');
        if ($limit === null && $remaining === null && $reset === null && $retryAfter === null) {
            return;
        }
        $retrySec = null;
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $retrySec = max(1, (int) $retryAfter);
        }
        $state = [
            'limit' => $limit !== null && is_numeric($limit) ? (int) $limit : null,
            'remaining' => $remaining !== null && is_numeric($remaining) ? (int) $remaining : null,
            'resetInSec' => $reset !== null && is_numeric($reset) ? (int) $reset : null,
            'retryAfterSec' => $retrySec,
            'lastUpdatedMs' => (int) round(microtime(true) * 1000),
        ];
        // Preserve prior limit/remaining if this response only had Retry-After
        if (session_status() === PHP_SESSION_ACTIVE && is_array($_SESSION['osmRateLimit'] ?? null)) {
            $prev = $_SESSION['osmRateLimit'];
            if ($state['limit'] === null && isset($prev['limit'])) {
                $state['limit'] = $prev['limit'];
            }
            if ($state['remaining'] === null && isset($prev['remaining'])) {
                $state['remaining'] = $prev['remaining'];
            }
            if ($state['resetInSec'] === null && isset($prev['resetInSec'])) {
                $state['resetInSec'] = $prev['resetInSec'];
            }
        }
        $this->lastRateLimit = $state;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['osmRateLimit'] = $state;
        }
    }
}
