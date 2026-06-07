<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

/**
 * Exchanges a refresh token for fresh OAuth credentials.
 *
 * Centralised refresh logic used by both {@see CodexAuthStorage}
 * (auto-refresh on read) and {@see CodexOAuthService}
 * (explicit refresh via auth:codex --refresh).
 *
 * The account ID extracted from the refreshed JWT is validated against
 * the previously stored account ID to detect credential theft or
 * unexpected account changes.
 */
class CodexTokenRefresher
{
    public function __construct(
        private int $port = CodexOAuthConfig::DEFAULT_PORT,
    ) {
    }

    /**
     * Exchange a refresh token for a new credential record.
     *
     * @param non-empty-string $refreshToken      The saved refresh token
     * @param non-empty-string $expectedAccountId Previously stored account ID for cross-check
     *
     * @throws \RuntimeException on network failure, missing fields, or account ID mismatch
     */
    public function refresh(string $refreshToken, string $expectedAccountId): CodexAuthRecord
    {
        $provider = new CodexOAuthProvider(CodexOAuthConfig::providerOptions($this->port));

        try {
            $token = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $refreshToken,
            ]);
        } catch (IdentityProviderException $e) {
            throw new \RuntimeException(\sprintf('Token refresh failed: %s. Run bin/console auth:codex to re-authenticate.', $e->getMessage()), previous: $e);
        }

        $accessToken = $token->getToken();
        $newRefreshToken = $token->getRefreshToken();
        $expires = $token->getExpires();

        if (null === $accessToken || null === $newRefreshToken || null === $expires) {
            throw new \RuntimeException('Token refresh response missing required fields (access, refresh, expires).');
        }

        $accountId = CodexAccountIdExtractor::extract($accessToken);
        if (null === $accountId) {
            throw new \RuntimeException('Failed to extract account ID from refreshed token. Run bin/console auth:codex to re-authenticate.');
        }

        if ($accountId !== $expectedAccountId) {
            throw new \RuntimeException(\sprintf('Account ID changed from "%s" to "%s" after token refresh. Run bin/console auth:codex to re-authenticate.', $expectedAccountId, $accountId));
        }

        return new CodexAuthRecord(
            access: $accessToken,
            refresh: $newRefreshToken,
            expires: $expires,
            accountId: $accountId,
        );
    }
}
