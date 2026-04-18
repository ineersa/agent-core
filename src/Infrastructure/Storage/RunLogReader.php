<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

final readonly class RunLogReader
{
    public function __construct(private FilesystemOperator $filesystem)
    {
    }

    /**
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array
    {
        $eventsBySeq = [];

        foreach ($this->runLogPaths($runId) as $path) {
            try {
                $contents = $this->filesystem->read($path);
            } catch (FilesystemException $exception) {
                throw new \RuntimeException(\sprintf('Failed to read run log "%s".', $path), previous: $exception);
            }

            foreach (explode("\n", $contents) as $line) {
                $event = $this->eventFromJsonLine($runId, $line);
                if (null === $event) {
                    continue;
                }

                $eventsBySeq[$event->seq] = $event;
            }
        }

        ksort($eventsBySeq);

        return array_values($eventsBySeq);
    }

    /**
     * @return list<string>
     */
    private function runLogPaths(string $runId): array
    {
        $paths = [];

        try {
            $attributes = $this->filesystem->listContents('', true);
        } catch (FilesystemException $exception) {
            throw new \RuntimeException('Failed to enumerate run logs.', previous: $exception);
        }

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof FileAttributes) {
                continue;
            }

            if (!str_ends_with($attribute->path(), '/'.$runId.'.jsonl')
                && $attribute->path() !== $runId.'.jsonl') {
                continue;
            }

            $paths[] = $attribute->path();
        }

        sort($paths);

        return $paths;
    }

    private function eventFromJsonLine(string $runId, string $line): ?RunEvent
    {
        $trimmedLine = trim($line);
        if ('' === $trimmedLine) {
            return null;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($trimmedLine, true);
        if (!\is_array($payload)) {
            return null;
        }

        if (($payload['run_id'] ?? null) !== $runId) {
            return null;
        }

        if (!\is_int($payload['seq'] ?? null)
            || !\is_int($payload['turn_no'] ?? null)
            || !\is_string($payload['type'] ?? null)
            || !\is_array($payload['payload'] ?? null)) {
            return null;
        }

        $createdAt = null;
        if (\is_string($payload['ts'] ?? null)) {
            try {
                $createdAt = new \DateTimeImmutable($payload['ts']);
            } catch (\Throwable) {
            }
        }

        return new RunEvent(
            runId: $runId,
            seq: $payload['seq'],
            turnNo: $payload['turn_no'],
            type: $payload['type'],
            payload: $payload['payload'],
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
    }
}
