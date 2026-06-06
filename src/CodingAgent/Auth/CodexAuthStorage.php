<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * File-backed credential storage for OAuth tokens.
 *
 * Stores credentials at ~/.hatfield/auth.json with mode 0600.
 * Supports multiple provider keys in the same file.
 * Uses Symfony Lock (flock) for atomic read-modify-write during refresh.
 *
 * Automatically refreshes expired tokens via an internal refresh handler
 * that uses the same GenericProvider configuration as the OAuth service.
 *
 * @see CodexAuthRecord
 */
final class CodexAuthStorage
{
    private ?\Closure $refreshHandler = null;

    public function __construct(
        private readonly string $homeDir,
        private readonly LockFactory $lockFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // Set up the internal refresh handler that can refresh expired
        // Codex OAuth tokens without creating a circular dependency on
        // CodexOAuthService. Uses centralized provider config from
        // CodexOAuthConfig and validates account ID stays stable.
        $this->refreshHandler = static function (string $refreshToken, string $storedAccountId): CodexAuthRecord {
            $provider = new GenericProvider(CodexOAuthConfig::providerOptions());

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
                throw new \RuntimeException('Token refresh response missing required fields.');
            }

            $accountId = CodexAccountIdExtractor::extract($accessToken);
            if (null === $accountId) {
                throw new \RuntimeException('Failed to extract account ID from refreshed token.');
            }

            // Validate account ID hasn't changed
            if ($accountId !== $storedAccountId) {
                throw new \RuntimeException(\sprintf('Account ID changed from "%s" to "%s" after token refresh. Run bin/console auth:codex to re-authenticate.', $storedAccountId, $accountId));
            }

            return new CodexAuthRecord(
                access: $accessToken,
                refresh: $newRefreshToken,
                expires: $expires,
                accountId: $accountId,
            );
        };
    }

    /**
     * Load credentials for the given provider key.
     *
     * Auto-refreshes if the stored record is expired and a refresh
     * handler is configured. The new record is persisted atomically.
     *
     * @return CodexAuthRecord|null Null when no credentials exist
     */
    public function loadCredentials(string $providerKey = CodexOAuthConfig::PROVIDER_KEY): ?CodexAuthRecord
    {
        $data = $this->readFromFile();
        $entry = $data[$providerKey] ?? null;

        if (null === $entry || !\is_array($entry)) {
            return null;
        }

        $record = CodexAuthRecord::fromArray($entry);

        // If expired and we have a refresh handler, try refreshing
        if ($record->isExpired() && null !== $this->refreshHandler) {
            try {
                $fresh = ($this->refreshHandler)($record->refresh, $record->accountId);
                $this->saveCredentials($providerKey, $fresh);

                return $fresh;
            } catch (\Throwable $e) {
                // Log the failure and return expired record so the caller
                // knows auth needs re-establishment.
                if (null !== $this->logger) {
                    $this->logger->warning('Codex token refresh failed, returning expired record', [
                        'provider_key' => $providerKey,
                        'component' => 'codex_auth_storage',
                        'event_type' => 'codex_token_refresh_failed',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $record;
    }

    /**
     * Persist a credential record atomically.
     */
    public function saveCredentials(string $providerKey, CodexAuthRecord $record): void
    {
        $lock = $this->lockFactory->createLock('codex-auth-'.$providerKey);
        $lock->acquire(true);

        try {
            $data = $this->readFromFile();
            $data[$providerKey] = $record->toArray();
            $this->writeToFile($data);
        } finally {
            $lock->release();
        }
    }

    /**
     * Remove stored credentials for a provider key.
     */
    public function removeCredentials(string $providerKey): void
    {
        $lock = $this->lockFactory->createLock('codex-auth-'.$providerKey);
        $lock->acquire(true);

        try {
            $data = $this->readFromFile();
            unset($data[$providerKey]);
            $this->writeToFile($data);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readFromFile(): array
    {
        $path = $this->authJsonPath();

        if (!@is_readable($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if (false === $content || '' === trim($content)) {
            return [];
        }

        try {
            $data = json_decode($content, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Corrupt auth.json at %s: %s', $path, $e->getMessage()), previous: $e);
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeToFile(array $data): void
    {
        $path = $this->authJsonPath();
        $dir = \dirname($path);

        if (!@is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        // Write to a temp file and chmod before rename to avoid a world-readable
        // window on the credentials (TOCTOU). The temp file is in the same
        // directory so the rename is atomic within the same filesystem.
        $tmpPath = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $written = @file_put_contents($tmpPath, $json, \LOCK_EX);
        if (false === $written) {
            @unlink($tmpPath);
            throw new \RuntimeException(\sprintf('Cannot write auth credentials to %s', $path));
        }

        @chmod($tmpPath, 0600);

        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(\sprintf('Cannot rename auth credentials to %s', $path));
        }

        // Defensive chmod after rename (preserves 0600 even if umask interfered)
        @chmod($path, 0600);
    }

    private function authJsonPath(): string
    {
        return $this->homeDir.'/'.CodexOAuthConfig::AUTH_FILE;
    }
}
