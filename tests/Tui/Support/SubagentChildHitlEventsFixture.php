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
        $childRunId = SubagentChildLiveViewFixtureSupport::childRunId($sessionId);
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $childEvents = [
            SubagentChildLiveViewFixtureSupport::childEvent($childRunId, 1, 0, 'run_started', ['step_id' => 'cstart'], $now),
            SubagentChildLiveViewFixtureSupport::childEvent($childRunId, 2, 1, 'waiting_human', [
                'question_id' => 'q_child_hitl_e2e',
                'prompt' => 'Which file should the scout inspect next?',
                'schema' => ['type' => 'string', 'enum' => ['src/Tui', 'src/CodingAgent']],
                'ui_kind' => 'choice',
                'header' => 'Subagent scout asks',
                'tool_call_id' => 'call_child_ask',
                'tool_name' => 'ask_human',
            ], $now),
        ];

        SubagentChildLiveViewFixtureSupport::write(
            $projectDir,
            $sessionId,
            'needs_clarification',
            'waiting_human',
            $childEvents,
        );
    }
}
