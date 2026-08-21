<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Shared parent/child artifact scaffolding for subagent live-view fixtures.
 *
 * Keeps registry + child events.jsonl + parent progress-status patch plumbing in
 * one place so scenario fixtures only encode their distinct event payloads.
 */
final class SubagentChildLiveViewFixtureSupport
{
    public const string ARTIFACT_ID = 'agent_e2e_progress_fixture';

    public static function childRunId(string $sessionId): string
    {
        return $sessionId.'_child_scout_001';
    }

    /**
     * @param list<array<string, mixed>> $childEvents
     */
    public static function write(
        string $projectDir,
        string $sessionId,
        string $registryStatus,
        string $parentProgressStatus,
        array $childEvents,
    ): void {
        SubagentProgressEventsFixture::write($projectDir, $sessionId);

        $artifactId = self::ARTIFACT_ID;
        $childRunId = self::childRunId($sessionId);
        $parentDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        $agentsDir = $parentDir.'/artifacts/agents';
        $artifactDir = $agentsDir.'/'.$artifactId;
        foreach ([$agentsDir, $artifactDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException('Failed to create dir: '.$dir);
            }
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $artifactRel = 'artifacts/agents/'.$artifactId;

        $registry = [
            'schema_version' => 1,
            'entries' => [[
                'artifact_id' => $artifactId,
                'parent_run_id' => $sessionId,
                'agent_run_id' => $childRunId,
                'agent_name' => 'scout',
                'kind' => 'subagent',
                'status' => $registryStatus,
                'created_at' => $now,
                'paths' => [
                    'artifact_dir' => $artifactRel,
                    'metadata_path' => $artifactRel.'/metadata.json',
                    'handoff_path' => $artifactRel.'/handoff.md',
                    'events_path' => $artifactRel.'/events.jsonl',
                    'state_path' => $artifactRel.'/state.json',
                ],
            ]],
        ];
        file_put_contents($agentsDir.'/registry.json', json_encode($registry, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        $lines = implode("\n", array_map(static fn (array $e): string => json_encode($e, \JSON_THROW_ON_ERROR), $childEvents))."\n";
        file_put_contents($artifactDir.'/events.jsonl', $lines);

        $parentEventsPath = $parentDir.'/events.jsonl';
        $parentLines = file($parentEventsPath, \FILE_IGNORE_NEW_LINES);
        $patched = [];
        foreach ($parentLines as $line) {
            $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $progress = $row['payload']['subagent_progress'] ?? null;
            if (\is_array($progress) && ($progress['status'] ?? '') === 'completed') {
                $progress['status'] = $parentProgressStatus;
                $row['payload']['subagent_progress'] = $progress;
            }
            $patched[] = json_encode($row, \JSON_THROW_ON_ERROR);
        }
        file_put_contents($parentEventsPath, implode("\n", $patched)."\n");
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function childEvent(string $runId, int $seq, int $turn, string $type, array $payload, string $ts): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => $runId,
            'seq' => $seq,
            'turn_no' => $turn,
            'type' => $type,
            'payload' => $payload,
            'ts' => $ts,
        ];
    }
}
