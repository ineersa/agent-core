<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRefreshStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexTokenRefresher;
use Symfony\Component\Lock\LockFactory;

/**
 * Codex-keyed wrapper over {@see AuthCredentialFileStore}.
 *
 * File I/O and the single file-scoped lock live in the shared store.
 * This adapter owns the Hatfield credential path and auto-refresh policy.
 *
 * @see CodexAuthRecord
 */
final class CodexAuthStorage implements CodexAuthRefreshStorageInterface
{
    public const string AUTH_FILE = '.hatfield/auth.json';

    private readonly AuthCredentialFileStore $store;

    public function __construct(
        string $homeDir,
        LockFactory $lockFactory,
        private readonly ?CodexTokenRefresher $tokenRefresher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->store = new AuthCredentialFileStore(
            $homeDir.'/'.self::AUTH_FILE,
            $lockFactory,
        );
    }

    /**
     * Load Codex credentials.
     *
     * If the stored record is expired and a {@see CodexTokenRefresher}
     * is configured, the refresh is performed under the file lock and
     * the fresh record is persisted atomically before being returned.
     *
     * @return CodexAuthRecord|null Null when no credentials exist
     *
     * @throws \RuntimeException when refresh is needed but fails
     */
    public function loadCredentials(): ?CodexAuthRecord
    {
        return $this->store->withLock(function (): ?CodexAuthRecord {
            $entry = $this->store->get(CodexOAuthConfig::PROVIDER_KEY);

            if (null === $entry) {
                return null;
            }

            $record = CodexAuthRecord::fromArray($entry);

            // Auto-refresh expired credentials under lock so two processes
            // cannot both refresh the same expired token.
            if ($record->isExpired() && null !== $this->tokenRefresher) {
                try {
                    $fresh = $this->tokenRefresher->refresh($record->refresh, $record->accountId);
                    $this->store->set(CodexOAuthConfig::PROVIDER_KEY, $fresh->toArray());

                    return $fresh;
                } catch (\Throwable $e) {
                    if (null !== $this->logger) {
                        $this->logger->warning('Codex token refresh failed for expired record', [
                            'provider_key' => CodexOAuthConfig::PROVIDER_KEY,
                            'component' => 'codex_auth_storage',
                            'event_type' => 'codex_token_refresh_failed',
                        ]);
                    }

                    throw new \RuntimeException('Stored Codex credentials have expired and could not be refreshed. Run bin/console auth:codex to re-authenticate.', previous: $e);
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
    public function loadCredentialsRaw(): ?CodexAuthRecord
    {
        $entry = $this->store->get(CodexOAuthConfig::PROVIDER_KEY);

        if (null === $entry) {
            return null;
        }

        return CodexAuthRecord::fromArray($entry);
    }

    /**
     * Persist a credential record atomically.
     */
    public function saveCredentials(CodexAuthRecord $record): void
    {
        $this->store->withLock(function () use ($record): void {
            $this->store->set(CodexOAuthConfig::PROVIDER_KEY, $record->toArray());
        });
    }

    public function refreshWithLock(CodexTokenRefresher $refresher): CodexAuthRecord
    {
        return $this->store->withLock(function () use ($refresher): CodexAuthRecord {
            $entry = $this->store->get(CodexOAuthConfig::PROVIDER_KEY);
            if (null === $entry) {
                throw new \RuntimeException('No stored Codex credentials found.');
            }

            $record = CodexAuthRecord::fromArray($entry);
            $fresh = $refresher->refresh($record->refresh, $record->accountId);
            $this->store->set(CodexOAuthConfig::PROVIDER_KEY, $fresh->toArray());

            return $fresh;
        });
    }
}
