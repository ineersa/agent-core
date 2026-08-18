<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * Exchanges a refresh token for fresh Grok CLI OAuth credentials.
 *
 * Uses {@see CodexOAuthProvider} (league GenericProvider with approval_prompt
 * strip + empty client_secret omit) against auth.x.ai — those two fixes are
 * provider-agnostic and required for xAI as well.
 *
 * Unlike Codex: if the token response omits refresh_token, keep the old one
 * (xAI may rotate or omit depending on client registration).
 */
class GrokTokenRefresher
{
    public function __construct(
        private int $port = GrokOAuthConfig::DEFAULT_PORT,
    ) {
    }

    /**
     * Exchange a refresh token for a new credential record.
     *
     * @param non-empty-string $refreshToken The saved refresh token
     *
     * @throws \RuntimeException on network failure or missing access/expires
     */
    public function refresh(string $refreshToken): GrokAuthRecord
    {
        $provider = new CodexOAuthProvider(GrokOAuthConfig::providerOptions($this->port));

        try {
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (IdentityProviderException $e) {
            throw new \RuntimeException(\sprintf('Token refresh failed: %s. Run %s to re-authenticate.', $e->getMessage(), GrokOAuthConfig::authCommandHint()), previous: $e);
        }

        $accessToken = $token->getToken();
        $newRefreshToken = $token->getRefreshToken();
        $expires = $token->getExpires();

        if (null === $accessToken || null === $expires) {
            throw new \RuntimeException('Token refresh response missing required fields (access, expires).');
        }

        // xAI may omit refresh_token on refresh; keep the previous one.
        $refresh = (null !== $newRefreshToken && '' !== $newRefreshToken) ? $newRefreshToken : $refreshToken;

        return new GrokAuthRecord(
            access: $accessToken,
            refresh: $refresh,
            expires: $expires,
        );
    }
}
