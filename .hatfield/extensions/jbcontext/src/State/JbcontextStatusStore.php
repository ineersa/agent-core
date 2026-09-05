<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

/**
 * Atomic JSON status file under the project Hatfield tree.
 *
 * Shared by the extension-agent worker (writer) and the interactive TUI/tool
 * processes (readers). File locking keeps concurrent updates coherent.
 */
final class JbcontextStatusStore
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function read(): JbcontextSessionState
    {
        if (!is_file($this->path)) {
            return JbcontextSessionState::pending();
        }

        $raw = @file_get_contents($this->path);
        if (false === $raw || '' === trim($raw)) {
            return JbcontextSessionState::pending();
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return JbcontextSessionState::pending();
        }

        if (!\is_array($decoded)) {
            return JbcontextSessionState::pending();
        }

        /* @var array<string, mixed> $decoded */
        return JbcontextSessionState::fromArray($decoded);
    }

    public function write(JbcontextSessionState $state): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create jbcontext status directory "%s".', $dir));
        }

        $payload = json_encode($state->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
        $tmp = $this->path.'.tmp.'.bin2hex(random_bytes(4));
        if (false === @file_put_contents($tmp, $payload, \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Unable to write jbcontext status temp file "%s".', $tmp));
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException(\sprintf('Unable to publish jbcontext status file "%s".', $this->path));
        }
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
            $current = JbcontextSessionState::pending();
            if (\is_string($raw) && '' !== trim($raw)) {
                try {
                    $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                    if (\is_array($decoded)) {
                        /** @var array<string, mixed> $decoded */
                        $current = JbcontextSessionState::fromArray($decoded);
                    }
                } catch (\JsonException) {
                    $current = JbcontextSessionState::pending();
                }
            }

            $next = $mutator($current);
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
