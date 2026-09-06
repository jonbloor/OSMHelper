<?php

declare(strict_types=1);

namespace App\Osm;

use App\Config;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Thin wrapper around league/oauth2-client GenericProvider for OSM OAuth2.
 */
final class OsmOAuth
{
    private GenericProvider $provider;

    public function __construct()
    {
        $base = rtrim(Config::osmApiBase(), '/');

        $this->provider = new GenericProvider([
            'clientId' => Config::get('CLIENT_ID'),
            'clientSecret' => Config::get('CLIENT_SECRET'),
            'redirectUri' => Config::get('REDIRECT_URI'),
            'urlAuthorize' => $base . '/oauth/authorize',
            'urlAccessToken' => $base . '/oauth/token',
            'urlResourceOwnerDetails' => $base . '/oauth/resource',
            'scopes' => Config::OAUTH_SCOPES,
            'scopeSeparator' => ' ',
        ]);
    }

    public function getProvider(): GenericProvider
    {
        return $this->provider;
    }

    /**
     * Authorization URL including access_type=offline when OSM supports refresh tokens.
     */
    public function getAuthorizationUrl(): string
    {
        $url = $this->provider->getAuthorizationUrl([
            'scope' => Config::OAUTH_SCOPES,
            'access_type' => 'offline',
        ]);

        $_SESSION['oauth2state'] = $this->provider->getState();

        return $url;
    }

    public function getState(): ?string
    {
        return $this->provider->getState();
    }

    public function getAccessToken(string $code): AccessTokenInterface
    {
        return $this->provider->getAccessToken('authorization_code', [
            'code' => $code,
        ]);
    }
}
