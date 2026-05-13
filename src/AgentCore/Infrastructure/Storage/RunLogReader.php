<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

final readonly class RunLogReader
{
    private EventPayloadNormalizer $eventPayloadNormalizer;

    public function __construct(
        private FilesystemOperator $filesystem,
        ?EventPayloadNormalizer $eventPayloadNormalizer = null,
    ) {
        $this->eventPayloadNormalizer = $eventPayloadNormalizer ?? new EventPayloadNormalizer();
    }

    /**
     * returns all events for a given run ID by reading log files.
     *
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
     * generates file paths for a specific run ID.
     *
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

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($trimmedLine, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $event = $this->eventPayloadNormalizer->denormalizeRunEvent($payload);
        if (null === $event) {
            return null;
        }

        if ($event->runId !== $runId) {
            return null;
        }

        return $event;
    }
}
