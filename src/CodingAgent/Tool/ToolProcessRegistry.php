<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Cross-process tool process registry backed by locked JSONL storage.
 *
 * The controller process and Messenger tool consumer are separate processes.
 * This registry uses a JSONL file under .hatfield/tmp/ with file-level
 * locking (LOCK_EX) so both processes can read/write foreground/background
 * process records safely.
 *
 * @immutable operations are idempotent; write operations use file locking.
 */
final class ToolProcessRegistry
{
    private const string STORAGE_FILENAME = 'tool_process_registry.jsonl';

    private readonly string $storagePath;

    public function __construct(
        ?string $projectDir = null,
    ) {
        $projectDir ??= $this->detectProjectDir();
        $this->storagePath = $projectDir.'/.hatfield/tmp/'.self::STORAGE_FILENAME;
        $dir = \dirname($this->storagePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
    }

    /**
     * Register a process record.
     */
    public function register(ToolProcessRecordDTO $record): void
    {
        $line = json_encode($record->toArray())."\n";

        $handle = fopen($this->storagePath, 'a');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open process registry for writing: %s', $this->storagePath));
        }

        try {
            flock($handle, \LOCK_EX);
            fwrite($handle, $line);
            fflush($handle);
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Remove a process record by run ID and tool call ID.
     */
    public function unregister(string $runId, string $toolCallId): void
    {
        $records = $this->readRecords();
        $remaining = array_values(array_filter(
            $records,
            static fn (array $r) => $r['run_id'] !== $runId || $r['tool_call_id'] !== $toolCallId,
        ));

        $this->writeRecords($remaining);
    }

    /**
     * Return all foreground tool records for a given run.
     *
     * @return list<ToolProcessRecordDTO>
     */
    public function foregroundForRun(string $runId): array
    {
        $records = $this->readRecords();

        $filtered = array_values(array_filter(
            $records,
            static fn (array $r) => $r['run_id'] === $runId && $r['kind'] === ToolProcessKindEnum::ForegroundTool->value,
        ));

        return array_map(
            static fn (array $data) => ToolProcessRecordDTO::fromArray($data),
            $filtered,
        );
    }

    /**
     * Return all background tool records for a given run.
     *
     * @return list<ToolProcessRecordDTO>
     */
    public function backgroundForRun(string $runId): array
    {
        $records = $this->readRecords();

        $filtered = array_values(array_filter(
            $records,
            static fn (array $r) => $r['run_id'] === $runId && $r['kind'] === ToolProcessKindEnum::BackgroundTool->value,
        ));

        return array_map(
            static fn (array $data) => ToolProcessRecordDTO::fromArray($data),
            $filtered,
        );
    }

    /**
     * Remove stale records older than the given threshold.
     */
    public function pruneOlderThan(\DateTimeImmutable $threshold): int
    {
        $records = $this->readRecords();
        $ts = $threshold->getTimestamp();
        $remaining = array_values(array_filter(
            $records,
            static fn (array $r) => ($r['started_at'] ?? 0) >= $ts,
        ));

        $pruned = \count($records) - \count($remaining);
        if ($pruned > 0) {
            $this->writeRecords($remaining);
        }

        return $pruned;
    }

    /**
     * Remove all records for a given run.
     */
    public function removeRun(string $runId): int
    {
        $records = $this->readRecords();
        $remaining = array_values(array_filter(
            $records,
            static fn (array $r) => $r['run_id'] !== $runId,
        ));

        $removed = \count($records) - \count($remaining);
        if ($removed > 0) {
            $this->writeRecords($remaining);
        }

        return $removed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readRecords(): array
    {
        if (!is_file($this->storagePath)) {
            return [];
        }

        $handle = @fopen($this->storagePath, 'r');
        if (false === $handle) {
            return [];
        }

        $records = [];

        try {
            flock($handle, \LOCK_SH);
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ('' === $trimmed) {
                    continue;
                }

                $data = json_decode($trimmed, true);
                if (\is_array($data) && isset($data['run_id'], $data['tool_call_id'])) {
                    $records[] = $data;
                }
            }
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $records;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function writeRecords(array $records): void
    {
        $handle = @fopen($this->storagePath, 'c');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open process registry for writing: %s', $this->storagePath));
        }

        try {
            flock($handle, \LOCK_EX);
            ftruncate($handle, 0);

            foreach ($records as $record) {
                fwrite($handle, json_encode($record)."\n");
            }

            fflush($handle);
            flock($handle, \LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function detectProjectDir(): string
    {
        // Walk up from cwd looking for .hatfield directory.
        $dir = getcwd();
        while (false !== $dir) {
            if (is_dir($dir.'/.hatfield')) {
                return $dir;
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Cannot detect project root — no .hatfield directory found in any parent of '.getcwd());
    }
}
