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

        $prepared = self::withHistoryAnchors($events);
        $lines = array_map(
            static fn (array $event): string => json_encode($event, \JSON_THROW_ON_ERROR),
            $prepared,
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
            'turn_no' => 'run_started' === $type ? 0 : 1,
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function withHistoryAnchors(array $events): array
    {
        $normalized = [];
        $hasTurnAdvanced = false;
        $childRunId = 'child-run';
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'run_started') {
                $event['turn_no'] = 0;
            }
            if (($event['type'] ?? null) === 'turn_advanced') {
                $hasTurnAdvanced = true;
            }
            if (\is_string($event['run_id'] ?? null) && '' !== $event['run_id']) {
                $childRunId = $event['run_id'];
            }
            $normalized[] = $event;
        }

        if ($hasTurnAdvanced || [] === $normalized) {
            return $normalized;
        }

        $maxSeq = 0;
        foreach ($normalized as $event) {
            $maxSeq = max($maxSeq, (int) ($event['seq'] ?? 0));
        }

        $anchor = self::childEvent($childRunId, $maxSeq + 1, 'turn_advanced', ['turn_no' => 1]);
        $out = [];
        $inserted = false;
        foreach ($normalized as $event) {
            $out[] = $event;
            if (!$inserted && ($event['type'] ?? null) === 'run_started') {
                $out[] = $anchor;
                $inserted = true;
            }
        }
        if (!$inserted) {
            array_unshift($out, $anchor);
        }

        $seq = 1;
        foreach ($out as &$event) {
            $event['seq'] = $seq;
            ++$seq;
        }
        unset($event);

        return $out;
    }
}
