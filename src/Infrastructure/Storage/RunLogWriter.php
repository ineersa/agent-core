<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use League\Flysystem\FilesystemOperator;

/**
 * The RunLogWriter class provides a mechanism for persisting RunEvent instances to the filesystem. It formats event data into structured files based on run identifiers and timestamps, ensuring durable storage of agent execution logs.
 */
final readonly class RunLogWriter
{
    /**
     * initializes the writer with a filesystem operator for storage operations.
     */
    public function __construct(private FilesystemOperator $filesystem)
    {
    }

    /**
     * persists a RunEvent to the filesystem using a generated path based on run ID and timestamp.
     */
    public function append(RunEvent $event): void
    {
        $path = $this->pathForRun($event->runId, $event->createdAt);

        $entry = [
            'seq' => $event->seq,
            'ts' => $event->createdAt->format(\DATE_ATOM),
            'run_id' => $event->runId,
            'turn_no' => $event->turnNo,
            'type' => $event->type,
            'payload' => $event->payload,
        ];

        $json = json_encode($entry);
        if (false === $json) {
            return;
        }

        $line = $json."\n";

        try {
            $current = '';
            if ($this->filesystem->fileExists($path)) {
                $current = $this->filesystem->read($path);
            }

            $this->filesystem->write($path, $current.$line);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Failed to append run log entry.', previous: $exception);
        }
    }

    /**
     * generates a unique filesystem path for a run using its ID and creation timestamp.
     */
    private function pathForRun(string $runId, \DateTimeImmutable $at): string
    {
        return \sprintf('%s/%s/%s.jsonl', $at->format('Y'), $at->format('m'), $runId);
    }
}
