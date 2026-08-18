<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * File-backed credential storage for Grok CLI OAuth tokens.
 *
 * Stores credentials at ~/.hatfield/auth.json with mode 0600 under key
 * {@see GrokOAuthConfig::PROVIDER_KEY}. Shares the file with Codex entries.
 *
 * When a {@see GrokTokenRefresher} is configured, expired credentials are
 * auto-refreshed under the file lock before being returned.
 */
final class GrokAuthStorage
{
    public function __construct(
        private readonly string $homeDir,
        private readonly LockFactory $lockFactory,
        private readonly ?GrokTokenRefresher $tokenRefresher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
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
        $lock = $this->lockFactory->createLock('grok-auth-'.$providerKey);
        $lock->acquire(true);

        try {
            $entry = $this->readFromFile()[$providerKey] ?? null;

            if (null === $entry || !\is_array($entry)) {
                return null;
            }

            $record = GrokAuthRecord::fromArray($entry);

            if ($record->isExpired() && null !== $this->tokenRefresher) {
                try {
                    $fresh = $this->tokenRefresher->refresh($record->refresh);

                    $data = $this->readFromFile();
                    $data[$providerKey] = $fresh->toArray();
                    $this->writeToFile($data);

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
        } finally {
            $lock->release();
        }
    }

    /**
     * Load credentials from disk WITHOUT auto-refresh.
     */
    public function loadCredentialsRaw(string $providerKey = GrokOAuthConfig::PROVIDER_KEY): ?GrokAuthRecord
    {
        $entry = $this->readFromFile()[$providerKey] ?? null;

        if (null === $entry || !\is_array($entry)) {
            return null;
        }

        return GrokAuthRecord::fromArray($entry);
    }

    public function saveCredentials(string $providerKey, GrokAuthRecord $record): void
    {
        $lock = $this->lockFactory->createLock('grok-auth-'.$providerKey);
        $lock->acquire(true);

        try {
            $data = $this->readFromFile();
            $data[$providerKey] = $record->toArray();
            $this->writeToFile($data);
        } finally {
            $lock->release();
        }
    }

    public function removeCredentials(string $providerKey): void
    {
        $lock = $this->lockFactory->createLock('grok-auth-'.$providerKey);
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

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        try {
            AtomicFileWriter::write($path, $json, fileMode: 0600, directoryMode: 0700);
        } catch (AtomicFileWriterException $exception) {
            throw new \RuntimeException('rename' === $exception->stage ? \sprintf('Cannot rename auth credentials to %s', $path) : \sprintf('Cannot write auth credentials to %s', $path), previous: $exception);
        }
    }

    private function authJsonPath(): string
    {
        return $this->homeDir.'/'.GrokOAuthConfig::AUTH_FILE;
    }
}
