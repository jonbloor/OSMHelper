<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Config;
use App\Http\OAuthState;
use App\Osm\OsmApi;
use App\Osm\OsmOAuth;
use Throwable;

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
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta http-equiv="refresh" content="0;url=' . $esc . '">';
        echo '<title>Connecting to OSM…</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5}</style>';
        echo '</head><body>';
        echo '<p>Connecting to Online Scout Manager…</p>';
        echo '<p><a href="' . $esc . '">Continue</a> if you are not redirected.</p>';
        echo '<script>location.replace(' . $js . ');</script>';
        echo '</body></html>';
        exit;
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
            http_response_code(400);
            App::render('error.twig', [
                'title' => 'Bad request',
                'message' => OAuthState::userMessage($reason),
            ]);
            return;
        }

        try {
            $oauth = new OsmOAuth();
            $token = $oauth->getAccessToken($code);
            $accessToken = $token->getToken();

            $_SESSION['accessToken'] = $accessToken;

            $api = new OsmApi();
            $resource = $api->get($accessToken, '/oauth/resource');
            $data = $resource['data'] ?? $resource;
            if (!is_array($data)) {
                $data = [];
            }

            $_SESSION['email'] = (string) ($data['email'] ?? 'Unknown Email');
            $_SESSION['fullName'] = (string) ($data['full_name'] ?? 'Unknown User');

            $sections = $data['sections'] ?? [];
            $groupName = 'OSM Helper';
            if (is_array($sections) && isset($sections[0]) && is_array($sections[0])) {
                $groupName = (string) ($sections[0]['group_name'] ?? $groupName);
                $_SESSION['_sections_count'] = count($sections);
            } else {
                $_SESSION['_sections_count'] = 0;
            }
            $_SESSION['groupName'] = $groupName;

            header('Location: /');
            exit;
        } catch (Throwable $e) {
            error_log('OAuth callback failed: ' . $e->getMessage());
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
