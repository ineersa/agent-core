<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use League\Flysystem\FilesystemOperator;

final readonly class RunLogWriter
{
    public function __construct(private FilesystemOperator $filesystem)
    {
    }

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

    private function pathForRun(string $runId, \DateTimeImmutable $at): string
    {
        return \sprintf('%s/%s/%s.jsonl', $at->format('Y'), $at->format('m'), $runId);
    }
}
