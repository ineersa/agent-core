<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use League\Flysystem\FilesystemOperator;

final readonly class RunLogWriter
{
    private EventPayloadNormalizer $eventPayloadNormalizer;

    public function __construct(
        private FilesystemOperator $filesystem,
        ?EventPayloadNormalizer $eventPayloadNormalizer = null,
    ) {
        $this->eventPayloadNormalizer = $eventPayloadNormalizer ?? new EventPayloadNormalizer();
    }

    public function append(RunEvent $event): void
    {
        $path = $this->pathForRun($event->runId, $event->createdAt);
        $entry = $this->eventPayloadNormalizer->normalizeRunEvent($event);

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

    private function pathForRun(string $runId, \DateTimeImmutable $at): string
    {
        return \sprintf('%s/%s/%s.jsonl', $at->format('Y'), $at->format('m'), $runId);
    }
}
