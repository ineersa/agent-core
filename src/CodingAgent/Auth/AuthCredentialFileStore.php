<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Auth;

use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use Symfony\Component\Lock\LockFactory;

/**
 * Shared RMW store for ~/.hatfield/auth.json.
 *
 * One file-scoped lock key ({@see self::LOCK_KEY}) serializes ALL callers —
 * Codex, Grok, and any future provider — so concurrent refresh cannot drop
 * a sibling provider entry (last-writer-wins on the whole file).
 *
 * Mutating helpers ({@see set}, {@see remove}) do NOT acquire the lock
 * themselves: flock is non-reentrant, so nested acquire would deadlock
 * under {@see withLock}. Callers that mutate must wrap via withLock.
 * Unlocked {@see get} is intentional for raw/no-refresh reads (same race
 * window the previous loadCredentialsRaw paths already accepted).
 */
final class AuthCredentialFileStore
{
    /**
     * Single lock key for the whole auth.json file, regardless of provider.
     * Must not be derived from providerKey — that was the race.
     */
    public const string LOCK_KEY = 'auth.json';

    public function __construct(
        private readonly string $authJsonPath,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Run $fn under the file-scoped lock. Use for every read-modify-write.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function withLock(callable $fn): mixed
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY);
        $lock->acquire(true);

        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * Unlocked read of one provider key. Prefer calling inside {@see withLock}
     * when the result drives a subsequent write.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $providerKey): ?array
    {
        $entry = $this->readAll()[$providerKey] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    /**
     * Unlocked set of one provider key. MUST be called under {@see withLock}.
     *
     * @param array<string, mixed> $record
     */
    public function set(string $providerKey, array $record): void
    {
        $data = $this->readAll();
        $data[$providerKey] = $record;
        $this->writeAll($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function readAll(): array
    {
        $path = $this->authJsonPath;

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
    public function writeAll(array $data): void
    {
        $path = $this->authJsonPath;

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        try {
            AtomicFileWriter::write($path, $json, fileMode: 0600, directoryMode: 0700);
        } catch (AtomicFileWriterException $exception) {
            throw new \RuntimeException('rename' === $exception->stage ? \sprintf('Cannot rename auth credentials to %s', $path) : \sprintf('Cannot write auth credentials to %s', $path), previous: $exception);
        }
    }
}
