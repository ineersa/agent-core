<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

/**
 * Constants and configuration for the xAI Grok CLI OAuth PKCE flow.
 *
 * Mirrors pi-grok-cli src/auth/config.ts + oauth.ts:
 *   issuer https://auth.x.ai, client id for grok-cli, callback path /callback.
 *
 * Shares ~/.hatfield/auth.json with Codex (separate provider key).
 */
final class GrokOAuthConfig
{
    /** xAI OAuth client ID for the official grok CLI / cli-chat-proxy. */
    public const string CLIENT_ID = 'b1a00492-073a-47ea-816f-4c329264a828';

    /** xAI OAuth authorization endpoint. */
    public const string AUTHORIZE_URL = 'https://auth.x.ai/oauth2/authorize';

    /** xAI OAuth token endpoint. */
    public const string TOKEN_URL = 'https://auth.x.ai/oauth2/token';

    /** OAuth scopes requested for Grok CLI access. */
    public const string SCOPE = 'openid profile email offline_access grok-cli:access api:access';

    /** Relative path (under ~/.hatfield/) for the shared auth credentials file. */
    public const string AUTH_FILE = '.hatfield/auth.json';

    /**
     * Default local TCP port for the OAuth callback server.
     * Same port pi-grok-cli uses; distinct from Codex's 1455.
     */
    public const int DEFAULT_PORT = 56122;

    /** Default timeout in seconds for the full login flow. */
    public const int DEFAULT_TIMEOUT = 300;

    /** Provider key used in auth.json storage. */
    public const string PROVIDER_KEY = 'grok-cli';

    /** Redirect path registered with xAI (pi-grok-cli uses /callback, not /auth/callback). */
    public const string CALLBACK_PATH = '/callback';

    public static function authCommandHint(): string
    {
        return 'bin/console auth:grok';
    }

    /**
     * Redirect URI for the given port.
     */
    public static function redirectUriForPort(int $port = self::DEFAULT_PORT): string
    {
        return \sprintf('http://127.0.0.1:%d%s', $port, self::CALLBACK_PATH);
    }

    /**
     * Provider options array for the given port.
     *
     * Reuses {@see CodexOAuthProvider}: league's GenericProvider injects
     * approval_prompt and an empty client_secret; those Hydra-style quirks
     * also break xAI's token endpoint, so the same strip/omit fixes apply.
     *
     * @return array<string, mixed>
     */
    public static function providerOptions(int $port = self::DEFAULT_PORT): array
    {
        return [
            'clientId' => self::CLIENT_ID,
            'clientSecret' => '',
            'redirectUri' => self::redirectUriForPort($port),
            'urlAuthorize' => self::AUTHORIZE_URL,
            'urlAccessToken' => self::TOKEN_URL,
            'urlResourceOwnerDetails' => '',
            'pkceMethod' => 'S256',
        ];
    }
}
