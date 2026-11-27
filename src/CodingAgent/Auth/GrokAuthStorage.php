<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Grok-keyed wrapper over {@see AuthCredentialFileStore}.
 *
 * File I/O and the single file-scoped lock live in
 * the shared store; this class only owns Grok record typing + auto-refresh.
 */
final class GrokAuthStorage
{
    private readonly AuthCredentialFileStore $store;

    public function __construct(
        string $homeDir,
        LockFactory $lockFactory,
        private readonly GrokTokenRefresher $tokenRefresher,
        private readonly LoggerInterface $logger,
    ) {
        $this->store = new AuthCredentialFileStore(
            $homeDir.'/'.GrokOAuthConfig::AUTH_FILE,
            $lockFactory,
        );
    }

    /**
     * Load Grok credentials.
     *
     * @return GrokAuthRecord|null Null when no credentials exist
     *
     * @throws \RuntimeException when refresh is needed but fails
     */
    public function loadCredentials(): ?GrokAuthRecord
    {
        return $this->loadWithRefresh(force: false);
    }

    /**
     * Load credentials from disk WITHOUT auto-refresh.
     */
    public function loadCredentialsRaw(): ?GrokAuthRecord
    {
        $entry = $this->store->get(GrokOAuthConfig::PROVIDER_KEY);

        if (null === $entry) {
            return null;
        }

        return GrokAuthRecord::fromArray($entry);
    }

    public function saveCredentials(GrokAuthRecord $record): void
    {
        $this->store->withLock(function () use ($record): void {
            $this->store->set(GrokOAuthConfig::PROVIDER_KEY, $record->toArray());
        });
    }

    public function refreshWithLock(): GrokAuthRecord
    {
        return $this->loadWithRefresh(force: true) ?? throw new \RuntimeException(\sprintf('No stored Grok credentials found. Run %s first.', GrokOAuthConfig::authCommandHint()));
    }

    private function loadWithRefresh(bool $force): ?GrokAuthRecord
    {
        return $this->store->withLock(function () use ($force): ?GrokAuthRecord {
            $record = $this->loadCredentialsRaw();
            if (null === $record || (!$force && !$record->isExpired())) {
                return $record;
            }

            try {
                $fresh = $this->tokenRefresher->refresh($record->refresh);
                $this->store->set(GrokOAuthConfig::PROVIDER_KEY, $fresh->toArray());

                return $fresh;
            } catch (\Throwable $e) {
                if (!$force) {
                    $this->logger->warning('Grok token refresh failed for expired record', [
                        'provider_key' => GrokOAuthConfig::PROVIDER_KEY,
                        'component' => 'grok_auth_storage',
                        'event_type' => 'grok_token_refresh_failed',
                    ]);
                }

                $hint = GrokOAuthConfig::authCommandHint();
                $message = $force
                    ? "Token refresh failed for stored Grok credentials. Run {$hint} to re-authenticate."
                    : "Stored Grok credentials have expired and could not be refreshed. Run {$hint} to re-authenticate.";

                throw new \RuntimeException($message, previous: $e);
            }
        });
    }
}
