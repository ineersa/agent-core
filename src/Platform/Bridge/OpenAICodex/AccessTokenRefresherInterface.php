<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Force-refreshes the Codex OAuth access token (e.g. after a 401).
 *
 * Implemented by application-layer auth adapters so the bridge can
 * recover from an expired/revoked token mid-run without depending on
 * app internals.
 */
interface AccessTokenRefresherInterface
{
    /**
     * Returns a new bearer token after force-refresh, or null when unavailable
     * without throwing.
     *
     * Implementations SHOULD propagate refresh failures by throwing; the caller
     * ({@see CodexModelClient}) catches, logs {@code codex.token.refresh_failed},
     * and gives up cleanly (original 401 surfaces).
     */
    public function refreshAccessToken(): ?string;
}
