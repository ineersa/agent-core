<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Grok-keyed wrapper over {@see AuthCredentialFileStore}.
 *
 * Public API unchanged. File I/O and the single file-scoped lock live in
 * the shared store; this class only owns Grok record typing + auto-refresh.
 */
final class GrokAuthStorage
{
    private readonly AuthCredentialFileStore $store;

    public function __construct(
        string $homeDir,
        LockFactory $lockFactory,
        private readonly ?GrokTokenRefresher $tokenRefresher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->store = new AuthCredentialFileStore(
            $homeDir.'/'.GrokOAuthConfig::AUTH_FILE,
            $lockFactory,
        );
    }

    /**
     * Load credentials for the given provider key.
     *
     * @return GrokAuthRecord|null Null when no credentials exist
     *
     * @throws \RuntimeException when refresh is needed but fails
     */
    public function loadCredentials(string $providerKey = GrokOAuthConfig::PROVIDER_KEY): ?GrokAuthRecord
    {
        return $this->store->withLock(function () use ($providerKey): ?GrokAuthRecord {
            $entry = $this->store->get($providerKey);

            if (null === $entry) {
                return null;
            }

            $record = GrokAuthRecord::fromArray($entry);

            if ($record->isExpired() && null !== $this->tokenRefresher) {
                try {
                    $fresh = $this->tokenRefresher->refresh($record->refresh);
                    $this->store->set($providerKey, $fresh->toArray());

                    return $fresh;
                } catch (\Throwable $e) {
                    if (null !== $this->logger) {
                        $this->logger->warning('Grok token refresh failed for expired record', [
                            'provider_key' => $providerKey,
                            'component' => 'grok_auth_storage',
                            'event_type' => 'grok_token_refresh_failed',
                        ]);
                    }

                    $hint = GrokOAuthConfig::authCommandHint();

                    throw new \RuntimeException("Stored Grok credentials have expired and could not be refreshed. Run {$hint} to re-authenticate.", previous: $e);
                }
            }

            return $record;
        });
    }

    /**
     * Load credentials from disk WITHOUT auto-refresh.
     */
    public function loadCredentialsRaw(string $providerKey = GrokOAuthConfig::PROVIDER_KEY): ?GrokAuthRecord
    {
        $entry = $this->store->get($providerKey);

        if (null === $entry) {
            return null;
        }

        return GrokAuthRecord::fromArray($entry);
    }

    public function saveCredentials(string $providerKey, GrokAuthRecord $record): void
    {
        $this->store->withLock(function () use ($providerKey, $record): void {
            $this->store->set($providerKey, $record->toArray());
        });
    }

}
