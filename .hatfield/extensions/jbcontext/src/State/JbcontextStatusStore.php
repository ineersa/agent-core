<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

/**
 * Locked JSON status file for one Hatfield session.
 *
 * Shared by the extension-agent worker (writer) and interactive TUI/tool
 * processes (readers). Readers take a shared flock; mutations take an exclusive
 * flock on the same target file so readers never observe the truncate window
 * and concurrent writers cannot orphan each other.
 */
final class JbcontextStatusStore
{
    public function __construct(
        private readonly string $path,
        private readonly string $sessionId,
    ) {
        if ('' === trim($this->sessionId)) {
            throw new \InvalidArgumentException('JbcontextStatusStore sessionId must be non-empty.');
        }
    }

    public static function forSession(JbcontextPaths $paths, string $sessionId): self
    {
        return new self($paths->sessionStatusPath($sessionId), $sessionId);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function read(): JbcontextSessionState
    {
        if (!is_file($this->path)) {
            return JbcontextSessionState::pending($this->sessionId);
        }

        $handle = @fopen($this->path, 'rb');
        if (false === $handle) {
            return JbcontextSessionState::pending($this->sessionId);
        }

        try {
            if (!flock($handle, \LOCK_SH)) {
                return JbcontextSessionState::pending($this->sessionId);
            }

            $raw = stream_get_contents($handle);
            if (false === $raw || '' === trim($raw)) {
                return JbcontextSessionState::pending($this->sessionId);
            }

            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return JbcontextSessionState::pending($this->sessionId);
            }

            if (!\is_array($decoded)) {
                return JbcontextSessionState::pending($this->sessionId);
            }

            try {
                /** @var array<string, mixed> $decoded */
                $state = JbcontextSessionState::fromArray($decoded);
            } catch (\InvalidArgumentException) {
                return JbcontextSessionState::pending($this->sessionId);
            }

            if ($state->sessionId !== $this->sessionId) {
                return JbcontextSessionState::pending($this->sessionId);
            }

            return $state;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function write(JbcontextSessionState $state): void
    {
        $this->update(static fn (): JbcontextSessionState => $state);
    }

    /**
     * @param callable(JbcontextSessionState): JbcontextSessionState $mutator
     */
    public function update(callable $mutator): JbcontextSessionState
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create jbcontext status directory "%s".', $dir));
        }

        $handle = @fopen($this->path, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Unable to open jbcontext status file "%s".', $this->path));
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException(\sprintf('Unable to lock jbcontext status file "%s".', $this->path));
            }

            $raw = stream_get_contents($handle);
            $current = JbcontextSessionState::pending($this->sessionId);
            if (\is_string($raw) && '' !== trim($raw)) {
                try {
                    $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                    if (\is_array($decoded)) {
                        /** @var array<string, mixed> $decoded */
                        $parsed = JbcontextSessionState::fromArray($decoded);
                        if ($parsed->sessionId === $this->sessionId) {
                            $current = $parsed;
                        }
                    }
                } catch (\JsonException|\InvalidArgumentException) {
                    $current = JbcontextSessionState::pending($this->sessionId);
                }
            }

            $next = $mutator($current);
            if ($next->sessionId !== $this->sessionId) {
                throw new \InvalidArgumentException('Cannot write jbcontext status for a different session id.');
            }

            $payload = json_encode($next->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $payload);
            fflush($handle);

            return $next;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
