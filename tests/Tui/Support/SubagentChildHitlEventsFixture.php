<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Parent session fixture with waiting_human subagent progress for child HITL E2E.
 *
 * Child artifact registry and events.jsonl use production-compatible shapes so
 * AgentArtifactRegistry::loadRegistry() and AgentChildRunEventStore can resolve
 * the child run for controller drain + live-view polling.
 */
final class SubagentChildHitlEventsFixture
{
    public static function write(string $projectDir, string $sessionId): void
    {
        SubagentProgressEventsFixture::write($projectDir, $sessionId);

        $artifactId = 'agent_e2e_progress_fixture';
        $childRunId = $sessionId.'_child_scout_001';
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
                'status' => 'needs_clarification',
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

        $childEvents = [];
        $childEvents[] = self::childEvent($childRunId, 1, 0, 'run_started', ['step_id' => 'cstart'], $now);
        $childEvents[] = self::childEvent($childRunId, 2, 1, 'waiting_human', [
            'question_id' => 'q_child_hitl_e2e',
            'prompt' => 'Which file should the scout inspect next?',
            'schema' => ['type' => 'string', 'enum' => ['src/Tui', 'src/CodingAgent']],
            'ui_kind' => 'choice',
            'header' => 'Subagent scout asks',
            'tool_call_id' => 'call_child_ask',
            'tool_name' => 'ask_human',
        ], $now);

        $lines = implode("\n", array_map(static fn (array $e): string => json_encode($e, \JSON_THROW_ON_ERROR), $childEvents))."\n";
        file_put_contents($artifactDir.'/events.jsonl', $lines);

        $parentEventsPath = $parentDir.'/events.jsonl';
        $parentLines = file($parentEventsPath, \FILE_IGNORE_NEW_LINES);
        $patched = [];
        foreach ($parentLines as $line) {
            $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $progress = $row['payload']['subagent_progress'] ?? null;
            if (\is_array($progress) && ($progress['status'] ?? '') === 'completed') {
                $progress['status'] = 'waiting_human';
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
    private static function childEvent(string $runId, int $seq, int $turn, string $type, array $payload, string $ts): array
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
