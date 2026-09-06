<?php

declare(strict_types=1);

namespace App\Osm;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Bearer-authenticated OSM API client with rate-limit header tracking stub.
 */
final class OsmApi
{
    private Client $http;

    /** @var array{limit?: string, remaining?: string, reset?: string} */
    private array $lastRateLimit = [];

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim(Config::osmApiBase(), '/') . '/',
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $accessToken, string $path, array $query = []): array
    {
        return $this->request('GET', $accessToken, $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function post(string $accessToken, string $path, array $form = []): array
    {
        return $this->request('POST', $accessToken, $path, ['form_params' => $form]);
    }

    /**
     * @return array{limit?: string, remaining?: string, reset?: string}
     */
    public function getRateLimitSnapshot(): array
    {
        return $this->lastRateLimit;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    /**
     * @return list<array<string, mixed>>
     */
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
        return is_array($decoded) ? $decoded : ['_raw' => $body];
    }

    /**
     * Stub: record common rate-limit response headers for later UX.
     *
     * @param array<string, list<string>> $headers
     */
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

        $limit = $pick($headers, 'X-RateLimit-Limit', 'X-Ratelimit-Limit');
        $remaining = $pick($headers, 'X-RateLimit-Remaining', 'X-Ratelimit-Remaining');
        $reset = $pick($headers, 'X-RateLimit-Reset', 'X-Ratelimit-Reset');

        $this->lastRateLimit = array_filter([
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $reset,
        ], static fn ($v) => $v !== null);
    }
}
