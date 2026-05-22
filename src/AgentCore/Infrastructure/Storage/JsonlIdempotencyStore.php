<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\IdempotencyStoreInterface;

/**
 * JSONL-per-session idempotency store.
 *
 * Writes idempotency keys as lines to `.hatfield/sessions/<runId>/idempotency.jsonl`.
 * Uses LOCK_EX for atomic appends and LOCK_SH for safe concurrent reads.
 *
 * Cross-process safe: multiple consumers in different processes can
 * check and mark concurrently without corruption. Follows the same
 * pattern as SessionRunEventStore's events.jsonl.
 */
final class JsonlIdempotencyStore implements IdempotencyStoreInterface
{
    private string $sessionsBasePath;

    public function __construct(
        string $projectDir,
    ) {
        $this->sessionsBasePath = $projectDir.'/.hatfield/sessions';
    }

    /**
     * Override the sessions base directory at runtime.
     *
     * Called by InProcessAgentSessionClient::initializeSessionsBasePath()
     * before any run operations to ensure the store writes to the active
     * project CWD, not the app install root.
     */
    public function setSessionsBasePath(string $path): void
    {
        $this->sessionsBasePath = $path;
    }

    public function isHandled(string $scope, string $runId, string $idempotencyKey): bool
    {
        $path = $this->path($runId);

        if (!is_file($path)) {
            return false;
        }

        $needle = $this->formatKey($scope, $runId, $idempotencyKey);

        $handle = fopen($path, 'r');

        if (false === $handle) {
            return false;
        }

        $handled = false;

        try {
            flock($handle, \LOCK_SH);

            while (false !== ($line = fgets($handle))) {
                if (rtrim($line, "\r\n") === $needle) {
                    $handled = true;

                    break;
                }
            }
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }

        return $handled;
    }

    public function markHandled(string $scope, string $runId, string $idempotencyKey): void
    {
        $path = $this->path($runId);
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $line = $this->formatKey($scope, $runId, $idempotencyKey)."\n";

        // Atomic append with exclusive lock — safe across processes
        file_put_contents($path, $line, \FILE_APPEND | \LOCK_EX);
    }

    private function path(string $runId): string
    {
        return $this->sessionsBasePath.'/'.$runId.'/idempotency.jsonl';
    }

    private function formatKey(string $scope, string $runId, string $idempotencyKey): string
    {
        return \sprintf('%s|%s|%s', $scope, $runId, $idempotencyKey);
    }
}
