<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Config;
use App\Http\OAuthState;
use App\Http\SignInGuard;
use App\Osm\LoginSections;
use App\Osm\OsmApi;
use App\Osm\OsmOAuth;
use App\Osm\OsmBusyException;
use App\Osm\OsmRateGuard;
use Throwable;
use App\Support\LogSafe;

final class AuthController
{
    public function redirectToOsm(): void
    {
        if (!Config::isConfigured()) {
            http_response_code(503);
            App::render('setup.twig', [
                'title' => 'Setup required',
                'missing' => Config::missingRequired(),
            ]);
            return;
        }

        if (self::refuseIfOsmStopped()) {
            return;
        }

        // Loop guard: a second /auth visit within a few minutes with no successful sign-in (or a
        // "Try again here" link) shows a "Continue to OSM" button instead of going straight to OSM.
        // The button (?go=1) always starts the normal flow below.
        $attempts = SignInGuard::recordAttempt($_SESSION);
        $retry = ($_GET['retry'] ?? '') === '1';
        $go = ($_GET['go'] ?? '') === '1';
        if (SignInGuard::shouldPause($attempts, $retry, $go)) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            App::render('oauth-continue.twig', [
                'title' => 'Sign in with OSM',
                'retry' => $retry,
            ]);
            return;
        }

        $oauth = new OsmOAuth();
        $url = $oauth->getAuthorizationUrl();
        $state = $oauth->getState();
        if (!is_string($state) || $state === '') {
            $state = (string) ($_SESSION['oauth2state'] ?? '');
        }
        OAuthState::persist($state);
        // Flush session so oauth2state is on disk before the browser leaves.
        session_write_close();

        // 200 interstitial (not cross-site 302): Set-Cookie must land before OSM.
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $esc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $js = json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta http-equiv="refresh" content="0;url=' . $esc . '">';
        echo '<title>Connecting to OSM…</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5}.disclaimer{margin-top:2.5rem;font-size:.85rem;color:#555}</style>';
        echo '</head><body>';
        echo '<p>Connecting to Online Scout Manager…</p>';
        echo '<p><a href="' . $esc . '">Continue</a> if you are not redirected.</p>';
        echo '<p class="disclaimer">OSMHelper is an independent tool built by a Scout volunteer. It isn&#39;t made, run or endorsed by Online Scout Manager or The Scout Association.</p>';
        echo '<script>location.replace(' . $js . ');</script>';
        echo '</body></html>';
        exit;
    }

    /** Renders an error page (no OSM call) when OSM is blocked app-wide or state can't be saved. */
    private static function refuseIfOsmStopped(): bool
    {
        try {
            OsmRateGuard::assertAppMayCall();
            return false;
        } catch (OsmBusyException $e) {
            error_log('OSM API osm_call_refused ' . $e->reason());
            http_response_code(503);
            App::render('error.twig', [
                'title' => 'OSM is unavailable',
                'message' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /** 400 page with a "Try again here" button (to /auth/?retry=1) and the phone app tip. Never redirects. */
    private static function renderRetry(string $title, string $message, ?string $detail): void
    {
        http_response_code(400);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        App::render('oauth-retry.twig', [
            'title' => $title,
            'message' => $message,
            'detail' => $detail,
        ]);
    }

    public function callback(): void
    {
        if (!Config::isConfigured()) {
            http_response_code(503);
            App::render('setup.twig', [
                'title' => 'Setup required',
                'missing' => Config::missingRequired(),
            ]);
            return;
        }

        // OSM sent the leader back with an error (cancelled, refused, …): no token exchange, no redirect.
        if (isset($_GET['error'])) {
            OAuthState::consume(isset($_GET['state']) ? (string) $_GET['state'] : '');
            error_log('OAuth callback: OSM returned an error');
            self::renderRetry(
                'OSM sign-in was cancelled or refused',
                'Online Scout Manager didn\'t complete the sign-in, so you aren\'t signed in to OSMHelper. You can try again below.',
                null
            );
            return;
        }

        $code = isset($_GET['code']) ? (string) $_GET['code'] : '';
        if ($code === '') {
            http_response_code(400);
            App::render('error.twig', [
                'title' => 'Bad request',
                'message' => 'Missing code parameter.',
            ]);
            return;
        }

        $state = isset($_GET['state']) ? (string) $_GET['state'] : '';
        $reason = OAuthState::consume($state);
        if ($reason !== 'ok') {
            error_log('OAuth state rejected: ' . $reason);
            // Never redirect from here (that is how a sign-in loop starts): explain and offer a button.
            self::renderRetry(
                'Sign-in didn\'t finish',
                'Sign-in started in a different browser or app (for example the OSM app), so we can\'t finish it here. Please try again in this browser.',
                OAuthState::detail($reason)
            );
            return;
        }

        // X-Blocked / fail-closed: the token exchange is an OSM call too, so it stops as well.
        if (self::refuseIfOsmStopped()) {
            return;
        }
        try {
            $oauth = new OsmOAuth();
            $token = $oauth->getAccessToken($code);
            $accessToken = $token->getToken();

            // New session ID on sign-in (no session fixation; cookie re-sent so it counts from now),
            // and the session limit (2 hours by default) runs from here. See App::enforceSessionMaxAge().
            App::beginAuthenticatedSession();
            $_SESSION['accessToken'] = $accessToken;
            $refresh = $token->getRefreshToken();
            if (is_string($refresh) && $refresh !== '') {
                $_SESSION['refreshToken'] = $refresh;
            } else {
                unset($_SESSION['refreshToken']);
            }
            $expires = $token->getExpires();
            if (is_int($expires) && $expires > 0) {
                $_SESSION['accessTokenExpiresAt'] = $expires;
            } else {
                unset($_SESSION['accessTokenExpiresAt']);
            }
            unset($_SESSION['membersListResult'], $_SESSION['membershipDashboardResult']);

            $api = new OsmApi();
            $resource = $api->get($accessToken, '/oauth/resource', [], true); // sign-in is essential
            $data = $resource['data'] ?? $resource;
            if (!is_array($data)) {
                $data = [];
            }

            $_SESSION['email'] = (string) ($data['email'] ?? 'Unknown Email');
            $_SESSION['fullName'] = (string) ($data['full_name'] ?? 'Unknown User');

            // Waiting-list rank saves: remember who signed in and which scopes this login asked for / got.
            // All optional — login must not depend on OSM returning these fields.
            $userId = $data['user_id'] ?? $data['userid'] ?? $data['id'] ?? null;
            if (is_scalar($userId) && (string) $userId !== '') {
                $_SESSION['osmUserId'] = (string) $userId;
            } else {
                unset($_SESSION['osmUserId']);
            }
            $_SESSION['requestedScopes'] = Config::OAUTH_SCOPES;
            $granted = null;
            try {
                $tokenValues = $token->getValues();
                if (isset($tokenValues['scope']) && is_string($tokenValues['scope']) && $tokenValues['scope'] !== '') {
                    $granted = $tokenValues['scope'];
                }
            } catch (Throwable) {
            }
            if ($granted === null && isset($data['scopes'])) {
                if (is_array($data['scopes'])) {
                    $granted = implode(' ', array_map('strval', array_filter($data['scopes'], 'is_scalar')));
                } elseif (is_string($data['scopes'])) {
                    $granted = $data['scopes'];
                }
            }
            if ($granted !== null && $granted !== '') {
                $_SESSION['grantedScopes'] = $granted;
            } else {
                unset($_SESSION['grantedScopes']);
            }
            unset($_SESSION['memberWriteDenied'], $_SESSION['eventsScopeDenied']);

            $stored = LoginSections::remember(
                OsmApi::sectionsFromResource(is_array($resource) ? $resource : [])
            );
            $groupName = 'OSM Helper';
            if (isset($stored[0]['group_name']) && (string) $stored[0]['group_name'] !== '') {
                $groupName = (string) $stored[0]['group_name'];
            }
            $_SESSION['groupName'] = $groupName;

            // Back to the page that needed sign-in (same-site GET path only), else home.
            header('Location: ' . App::takeReturnPath());
            exit;
        } catch (Throwable $e) {
            error_log('OAuth callback failed: ' . LogSafe::reason($e));
            http_response_code(500);
            $msg = 'Authentication failed.';
            if (Config::debug()) {
                $msg .= ' ' . $e->getMessage();
            }
            App::render('error.twig', [
                'title' => 'Authentication failed',
                'message' => $msg,
            ]);
        }
    }

    public function logout(): void
    {
        // Top awards caches hold member names: clear this session's before the session goes.
        TopAwardsController::clearSessionCaches();
        \App\Osm\OsmCache::forgetCurrentUser(); // cached OSM data (waiting list, sections) for this user
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        setcookie(OAuthState::COOKIE, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
        header('Location: /');
        exit;
    }
}
