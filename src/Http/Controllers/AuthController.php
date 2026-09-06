<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\App;
use App\Config;
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
        // Persist oauth2state before leaving for OSM (fail-closed callback needs it).
        session_write_close();
        header('Location: ' . $url);
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
        $expected = $_SESSION['oauth2state'] ?? null;
        unset($_SESSION['oauth2state']);
        // Fail closed: require prior oauth2state and matching ?state=
        if (!is_string($expected) || $expected === '' || $state === '' || !hash_equals($expected, $state)) {
            http_response_code(400);
            App::render('error.twig', [
                'title' => 'Bad request',
                'message' => 'Invalid OAuth state. Please try connecting again.',
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

            // Only keep the four session fields long-term; drop ephemeral count helper after dashboard reads it
            // (_sections_count is P0 display only, not PII)

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
        session_destroy();
        header('Location: /');
        exit;
    }
}
