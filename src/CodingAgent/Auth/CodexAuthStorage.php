<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * File-backed credential storage for OAuth tokens.
 *
 * Stores credentials at ~/.hatfield/auth.json with mode 0600.
 * Supports multiple provider keys in the same file.
 * Uses Symfony Lock (flock) for atomic read-modify-write during refresh.
 *
 * When a {@see CodexTokenRefresher} is configured, expired credentials
 * are automatically refreshed under the file lock before being returned.
 * This prevents two concurrent processes from both attempting to refresh
 * the same expired token (the lock is held during the network refresh
 * call, which is acceptable for v1 since refresh only runs for expired
 * tokens and prevents refresh-token races).
 *
 * @see CodexAuthRecord
 */
final class CodexAuthStorage
{
    public function __construct(
        private readonly string $homeDir,
        private readonly LockFactory $lockFactory,
        private readonly ?CodexTokenRefresher $tokenRefresher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
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
        $lock = $this->lockFactory->createLock('codex-auth-'.$providerKey);
        $lock->acquire(true);

        try {
            $entry = $this->readFromFile()[$providerKey] ?? null;

            if (null === $entry || !\is_array($entry)) {
                return null;
            }

            $record = CodexAuthRecord::fromArray($entry);

            // Auto-refresh expired credentials under lock so two processes
            // cannot both refresh the same expired token.
            if ($record->isExpired() && null !== $this->tokenRefresher) {
                try {
                    $fresh = $this->tokenRefresher->refresh($record->refresh, $record->accountId);

                    // Persist fresh record under the same lock
                    $data = $this->readFromFile();
                    $data[$providerKey] = $fresh->toArray();
                    $this->writeToFile($data);

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
        } finally {
            $lock->release();
        }
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
        $entry = $this->readFromFile()[$providerKey] ?? null;

        if (null === $entry || !\is_array($entry)) {
            return null;
        }

        return CodexAuthRecord::fromArray($entry);
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
