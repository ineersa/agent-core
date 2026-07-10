<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Symfony\AI\Platform\Bridge\OpenAICodex\AccessTokenRefresherInterface;

/**
 * Application adapter: force-refresh Codex OAuth and return a new bearer token.
 *
 * Used by {@see \Symfony\AI\Platform\Bridge\OpenAICodex\CodexModelClient} after
 * a 401 so long-lived workers can recover without manual {@code auth:codex --refresh}.
 *
 * Refresh failures from {@see CodexOAuthService::refreshCredentials()} propagate;
 * the bridge client catches, logs {@code codex.token.refresh_failed}, and degrades
 * to the original 401 response.
 */
final class CodexAccessTokenRefresher implements AccessTokenRefresherInterface
{
    public function __construct(
        private readonly CodexOAuthService $oAuth,
        private readonly string $providerKey,
    ) {
    }

    public function refreshAccessToken(): string
    {
        return $this->oAuth->refreshCredentials($this->providerKey)->access;
    }
}
