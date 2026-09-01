<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Codex-keyed wrapper over {@see AuthCredentialFileStore}.
 *
 * Public API unchanged. File I/O and the single file-scoped lock live in
 * the shared store; this class only owns Codex record typing + auto-refresh.
 *
 * @see CodexAuthRecord
 */
final class CodexAuthStorage
{
    private readonly AuthCredentialFileStore $store;

    public function __construct(
        string $homeDir,
        LockFactory $lockFactory,
        private readonly ?CodexTokenRefresher $tokenRefresher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->store = new AuthCredentialFileStore(
            $homeDir.'/'.CodexOAuthConfig::AUTH_FILE,
            $lockFactory,
        );
    }

    /**
     * Load credentials for the given provider key.
     *
     * If the stored record is expired and a {@see CodexTokenRefresher}
     * is configured, the refresh is performed under the file lock and
     * the fresh record is persisted atomically before being returned.
     *
     * @return CodexAuthRecord|null Null when no credentials exist
     *
     * @throws \RuntimeException when refresh is needed but fails
     */
    public function loadCredentials(string $providerKey = CodexOAuthConfig::PROVIDER_KEY): ?CodexAuthRecord
    {
        return $this->store->withLock(function () use ($providerKey): ?CodexAuthRecord {
            $entry = $this->store->get($providerKey);

            if (null === $entry) {
                return null;
            }

            $record = CodexAuthRecord::fromArray($entry);

            // Auto-refresh expired credentials under lock so two processes
            // cannot both refresh the same expired token.
            if ($record->isExpired() && null !== $this->tokenRefresher) {
                try {
                    $fresh = $this->tokenRefresher->refresh($record->refresh, $record->accountId);
                    $this->store->set($providerKey, $fresh->toArray());

                    return $fresh;
                } catch (\Throwable $e) {
                    if (null !== $this->logger) {
                        $this->logger->warning('Codex token refresh failed for expired record', [
                            'provider_key' => $providerKey,
                            'component' => 'codex_auth_storage',
                            'event_type' => 'codex_token_refresh_failed',
                        ]);
                    }

                    $hint = CodexOAuthConfig::authCommandHintForProviderKey($providerKey);

                    throw new \RuntimeException("Stored Codex credentials have expired and could not be refreshed. Run {$hint} to re-authenticate.", previous: $e);
                }
            }

            return $record;
        });
    }

    /**
     * Load credentials from disk WITHOUT auto-refresh.
     *
     * Use this when you need the raw stored record regardless of expiry,
     * e.g. in {@see CodexOAuthService::refreshCredentials()} which wants
     * to call the refresher explicitly.
     */
    public function loadCredentialsRaw(string $providerKey = CodexOAuthConfig::PROVIDER_KEY): ?CodexAuthRecord
    {
        $entry = $this->store->get($providerKey);

        if (null === $entry) {
            return null;
        }

        return CodexAuthRecord::fromArray($entry);
    }

    /**
     * Persist a credential record atomically.
     */
    public function saveCredentials(string $providerKey, CodexAuthRecord $record): void
    {
        $this->store->withLock(function () use ($providerKey, $record): void {
            $this->store->set($providerKey, $record->toArray());
        });
    }
}
