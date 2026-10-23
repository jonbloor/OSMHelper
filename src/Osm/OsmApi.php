<?php
declare(strict_types=1);
namespace App\Osm;

use App\Config;
use App\Support\LogSafe;
use GuzzleHttp\Client;
/**
 * Bearer-authenticated OSM API client with rate-limit header tracking.
 */
final class OsmApi
{
    private Client $http;
    /** @var array{limit?: int|null, remaining?: int|null, resetInSec?: int|null, lastUpdatedMs?: int} */
    private array $lastRateLimit = [];
    /** Whether the most recent response carried any X-RateLimit-* header. */
    private bool $lastHadRateHeaders = false;
    /** Shape of the last 2xx body: empty | empty-object | empty-list | json | not-json (null before any reply). */
    private ?string $lastBodyKind = null;
    /** OSM user whose allowance this client spends; null = whoever is signed in to the session. */
    private ?string $osmUserId;
    /** When true, every call is treated as essential (a save the user confirmed, and its checks). */
    private bool $essentialScope = false;
    /** @var array<string, true> reason codes already logged in this PHP request */
    private static array $loggedReasons = [];

    /**
     * @param string|null $osmUserId OSM user whose allowance is spent (for token-owner calls such as the
     *                               WordPress form). Defaults to the signed-in session's osmUserId.
     */
    public function __construct(?Client $http = null, ?string $osmUserId = null)
    {
        $this->osmUserId = $osmUserId;
        $this->http = $http ?? new Client([
            'base_uri' => rtrim(Config::osmApiBase(), '/') . '/',
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param bool|null $essential null = reads are non-essential (refused while the allowance is low)
     * @return array<string, mixed>
     */
    public function get(string $accessToken, string $path, array $query = [], ?bool $essential = null): array
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
        return $this->request('GET', $accessToken, $path, $options, $essential ?? false);
    }

    /**
     * @param array<string, mixed> $form
     * @param bool|null $essential null = writes are essential; POST reads (action=get...) are not
     * @return array<string, mixed>
     */
    public function post(string $accessToken, string $path, array $form = [], ?bool $essential = null): array
    {
        if ($essential === null) {
            $essential = !preg_match('/[?&]action=get/i', $path);
        }
        return $this->request('POST', $accessToken, $path, ['form_params' => $form], $essential);
    }

    /**
     * Treat every call on this client as essential until switched off: use around a save the user
     * confirmed (writes plus their read-back checks) so a low allowance doesn't stop it half way.
     * A 429 backoff still refuses everything.
     */
    public function setEssential(bool $on): void
    {
        $this->essentialScope = $on;
    }

    /** The shared allowance guard for this client's OSM user. */
    public function rateGuard(): OsmRateGuard
    {
        return $this->osmUserId !== null ? OsmRateGuard::forUser($this->osmUserId) : OsmRateGuard::forCurrentUser();
    }

    /** Log a reason code (never names, tokens or OSM text), once per PHP request. */
    private static function logReason(string $code): void
    {
        if (isset(self::$loggedReasons[$code])) {
            return;
        }
        self::$loggedReasons[$code] = true;
        @error_log('OSM API ' . $code);
    }

    /** Test helper. */
    public static function resetLoggedReasons(): void
    {
        self::$loggedReasons = [];
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
    public function getDynamicSections(string $accessToken, ?bool $essential = null): array
    {
        return self::sectionsFromResource($this->get($accessToken, '/oauth/resource', [], $essential));
    }

    /**
     * Sections on an /oauth/resource payload, with current_term_id resolved from term dates.
     * Same extraction as getDynamicSections, so login and later tools share one list.
     *
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    public static function sectionsFromResource(array $response): array
    {
        $raw = $response['sections']
            ?? ($response['data']['sections'] ?? null)
            ?? $response['roles']
            ?? ($response['data']['roles'] ?? null)
            ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        return self::normalizeSections($raw);
    }

    /**
     * @param array<mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function normalizeSections(array $raw): array
    {
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
    private function request(string $method, string $accessToken, string $path, array $options = [], bool $essential = false): array
    {
        // Central allowance guard: refuse (no HTTP call) while this user is in backoff.
        $guard = $this->rateGuard();
        try {
            $guard->assertCanCall($essential || $this->essentialScope);
        } catch (OsmBusyException $busy) {
            self::logReason('osm_call_refused ' . $busy->reason());
            throw $busy;
        }
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
        $ctx = self::requestContext($path, $options);
        $this->lastBodyKind = null;
        try {
            $response = $this->http->request($method, $path, $options);
        } catch (\Throwable $e) {
            OsmErrorLog::log([
                'method' => $method,
                'endpoint' => $path,
                'action' => $ctx['action'],
                'http_status' => null,
                'osm_code' => null,
                'kind' => 'transport',
                'section_id' => $ctx['section_id'],
                'badge_id' => $ctx['badge_id'],
                'detail' => null,
            ]);
            throw new \RuntimeException('OSM transport error for /' . ltrim(explode('?', $path, 2)[0], '/') . ' — ' . $e->getMessage(), 0, $e);
        }
        $headers = $response->getHeaders();
        $this->captureRateLimit($headers);
        $status = $response->getStatusCode();
        $started = $guard->recordResponse($status, $headers);
        if ($started !== null) {
            self::logReason($started);
        }
        $body = (string) $response->getBody();

        $blocked = self::headerFirst($headers, 'X-Blocked', 'x-blocked');
        if ($blocked !== null) {
            $detail = ($blocked !== '' && $blocked !== '1' && strtolower($blocked) !== 'true') ? (': ' . $blocked) : '';
            OsmErrorLog::log([
                'method' => $method,
                'endpoint' => $path,
                'action' => $ctx['action'],
                'http_status' => $status,
                'osm_code' => null,
                'kind' => 'blocked',
                'section_id' => $ctx['section_id'],
                'badge_id' => $ctx['badge_id'],
                'detail' => null,
            ]);
            throw new \RuntimeException('OSM_BLOCKED: OSM blocked this application (X-Blocked' . $detail . '). Further OSM requests must stop.', 403);
        }

        $deprecated = self::headerFirst($headers, 'X-Deprecated', 'x-deprecated');
        if ($deprecated !== null && $deprecated !== '') {
            $ts = strtotime($deprecated);
            // Reason code + path only: never OSM's header text; the query string can carry section/member IDs.
            error_log('OSM API ' . (($ts !== false && $ts <= time()) ? 'osm_deprecated_removed' : 'osm_deprecated') . ' endpoint: ' . LogSafe::pathOnly($path));
            if ($ts !== false && $ts <= time()) {
                OsmErrorLog::log([
                    'method' => $method,
                    'endpoint' => $path,
                    'action' => $ctx['action'],
                    'http_status' => $status,
                    'osm_code' => null,
                    'kind' => 'deprecated-removed',
                    'section_id' => $ctx['section_id'],
                    'badge_id' => $ctx['badge_id'],
                    'detail' => null,
                ]);
                throw new \RuntimeException('OSM marked this endpoint removed (X-Deprecated). It will not be called again.', 410);
            }
        }

        if ($status >= 400) {
            $errHint = '';
            $osmMsg = null;
            $osmCode = null;
            $decodedErr = json_decode($body, true);
            if (is_array($decodedErr)) {
                $msgPart = $decodedErr['error']['message'] ?? ($decodedErr['error'] ?? null);
                $code = is_array($decodedErr['error'] ?? null) ? ($decodedErr['error']['code'] ?? null) : null;
                if (is_string($msgPart) && $msgPart !== '') {
                    $osmMsg = $msgPart;
                    $errHint = ' — ' . $msgPart . ($code ? " [{$code}]" : '');
                }
                if (is_string($code) || is_int($code)) {
                    $osmCode = (string) $code;
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
            $kind = 'http';
            if (str_contains($blob, 'invalid-action') || str_contains($blob, 'invalid action')) {
                $msg = 'OSM_INVALID_ACTION: ' . $msg;
                $kind = 'invalid-action';
            } elseif ($status === 429) {
                $kind = '429';
            } elseif ($status === 403) {
                $kind = '403';
            }
            if (OsmTokens::isNotLoggedInError($status, $osmCode, $osmMsg)) {
                // Expired/revoked session token: renew it on the next page instead of failing every page.
                OsmTokens::markRejected($accessToken);
            }
            OsmErrorLog::log([
                'method' => $method,
                'endpoint' => $path,
                'action' => $ctx['action'],
                'http_status' => $status,
                'osm_code' => $osmCode,
                'kind' => $kind,
                'section_id' => $ctx['section_id'],
                'badge_id' => $ctx['badge_id'],
                'detail' => null,
            ]);
            if ($status === 429) {
                // Stop at once: the guard has recorded backoff-until for this user. Never retried.
                throw new OsmBusyException(max(1, $guard->secondsLeft()), OsmRateGuard::REASON_429);
            }
            throw new \RuntimeException($msg, $status);
        }
        $this->lastBodyKind = self::bodyKind($body);
        return self::decodeBody($body);
    }

    /**
     * Shape of the last successful reply body. decodeBody() turns '', '{}' and '[]' all into [],
     * so callers that must tell "empty list" from "empty reply" check this instead.
     */
    public function lastBodyKind(): ?string
    {
        return $this->lastBodyKind;
    }

    /** empty ('' / whitespace) | empty-object ({}) | empty-list ([]) | json | not-json */
    public static function bodyKind(string $body): string
    {
        $t = trim($body);
        if ($t === '') {
            return 'empty';
        }
        // Same JS-assignment unwrapping as decodeBody().
        $t = rtrim(trim(preg_replace('/^(?:var\s+\w+\s*=\s*|window\.\w+\s*=\s*)/i', '', $t) ?? $t), ';');
        $d = $t === '' ? null : json_decode($t, true);
        if (!is_array($d)) {
            return 'not-json';
        }
        if ($d === []) {
            return $t[0] === '[' ? 'empty-list' : 'empty-object';
        }
        return 'json';
    }

    /**
     * Pull safe request context for error logs (no bodies / tokens / member IDs).
     * @param array<string, mixed> $options
     * @return array{action:?string,section_id:?string,badge_id:?string}
     */
    private static function requestContext(string $path, array $options): array
    {
        $params = [];
        $qPos = strpos($path, '?');
        if ($qPos !== false) {
            parse_str(substr($path, $qPos + 1), $params);
        }
        $form = $options['form_params'] ?? null;
        if (is_array($form)) {
            $params = array_merge($params, $form);
        }
        $pick = static function (array $params, string ...$keys): ?string {
            foreach ($keys as $k) {
                if (isset($params[$k]) && $params[$k] !== '' && $params[$k] !== null) {
                    return (string) $params[$k];
                }
            }
            return null;
        };
        return [
            'action' => $pick($params, 'action'),
            'section_id' => $pick($params, 'section_id', 'sectionid'),
            'badge_id' => $pick($params, 'badge_id', 'badgeid'),
        ];
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

    /** False when the last OSM response had no X-RateLimit-* headers (allowance unknown). */
    public function lastResponseHadRateHeaders(): bool
    {
        return $this->lastHadRateHeaders;
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
    private static function headerFirst(array $headers, string ...$names): ?string
    {
        foreach ($names as $name) {
            foreach ($headers as $key => $values) {
                if (strcasecmp((string) $key, $name) === 0 && isset($values[0])) {
                    return (string) $values[0];
                }
            }
        }
        return null;
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
        $this->lastHadRateHeaders = $limit !== null || $remaining !== null || $reset !== null;
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
