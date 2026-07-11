<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Writes canonical child artifact events.jsonl under a parent session directory.
 */
final class ChildAgentExportEventsFixture
{
    /**
     * @param list<array<string, mixed>> $events
     */
    public static function write(
        string $projectDir,
        string $parentSessionId,
        string $artifactId,
        array $events,
    ): void {
        $dir = $projectDir.'/.hatfield/sessions/'.$parentSessionId.'/artifacts/agents/'.$artifactId;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create child artifact dir: '.$dir);
        }

        $lines = array_map(
            static fn (array $event): string => json_encode($event, \JSON_THROW_ON_ERROR),
            $events,
        );
        file_put_contents($dir.'/events.jsonl', implode("\n", $lines)."\n");
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function childEvent(
        string $childRunId,
        int $seq,
        string $type,
        array $payload = [],
    ): array {
        return [
            'schema_version' => '1.0',
            'run_id' => $childRunId,
            'seq' => $seq,
            'turn_no' => 1,
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }
}
