<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Tools;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Privacy-safe measurement is exercised through the real dependency-free CLI.
 * The fixture deliberately contains sentinel values and identifiers; none may
 * cross the aggregate-only output boundary.
 */
final class SessionStorageAuditTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('session-storage-audit');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    #[Test]
    public function reportsPrivacySafeParentChildAttributionAndFinalSchemaProjection(): void
    {
        $parent = $this->projectDir.'/.hatfield/sessions/101/events.jsonl';
        $child = $this->projectDir.'/.hatfield/sessions/101/artifacts/agents/child-sentinel/events.jsonl';
        mkdir(\dirname($parent), 0777, true);
        mkdir(\dirname($child), 0777, true);

        $typedResult = [
            'run_id' => 'run-id-sentinel',
            'turn_no' => 1,
            'step_id' => 'step-id-sentinel',
            'attempt' => 1,
            'idempotency_key' => 'key-sentinel',
            'tool_call_id' => 'tool-id-sentinel',
            'order_index' => 0,
            'result' => [
                'tool_name' => 'tool-sentinel',
                'content' => [['type' => 'text', 'text' => 'tool-output-sentinel']],
                'details' => ['raw_result' => ['filename' => 'filename-sentinel']],
            ],
            'is_error' => false,
            'error' => null,
            'pending_human_input' => null,
        ];
        $parentEvents = [
            $this->event(1, 'agent_start'),
            $this->event(2, 'tool_call_result_received'),
            $this->event(3, 'tool_execution_end', ['tool_result' => $typedResult]),
            $this->event(4, 'message_start'),
            $this->event(5, 'message_end', ['message' => ['content' => 'tool-output-sentinel', 'details' => ['raw_result' => 'tool-output-sentinel']]]),
            $this->event(6, 'stale_result_ignored'),
            $this->event(7, 'llm_step_completed', [
                'text' => 'assistant-text-sentinel',
                'tool_calls_count' => 99,
                'assistant_message' => ['content' => [['type' => 'text', 'text' => 'assistant-text-sentinel']]],
            ]),
        ];
        $childEvents = [
            $this->event(1, 'turn_start'),
            $this->event(2, 'message_update'),
            $this->event(3, 'turn_end'),
            $this->event(4, 'model_changed'),
            $this->event(5, 'agent_command_superseded'),
            $this->event(6, 'run_started', ['messages' => [['content' => 'child-prompt-sentinel']]]),
        ];
        file_put_contents($parent, $this->jsonl($parentEvents));
        file_put_contents($child, $this->jsonl($childEvents));
        file_put_contents(\dirname($parent).'/state.json', json_encode(['runId' => 'run-id-sentinel', 'status' => 'running', 'turnNo' => 1, 'activeStepId' => 'step-id-sentinel', 'lastSeq' => 7, 'version' => 2, 'currentToolCalls' => [['batchId' => 'batch-sentinel', 'toolCallId' => 'tool-id-sentinel', 'orderIndex' => 0, 'status' => 'running', 'attempt' => 1]], 'pendingHumanInputRequests' => [['questionId' => 'question-sentinel', 'continuationKind' => 'tool_call', 'continuationRef' => ['tool_call_id' => 'tool-id-sentinel']]]], \JSON_THROW_ON_ERROR));
        file_put_contents(\dirname($child).'/state.json', json_encode(['runId' => 'child-id-sentinel', 'status' => 'completed', 'turnNo' => 1, 'lastSeq' => 6, 'version' => 1, 'currentToolCalls' => 'unsupported-shape'], \JSON_THROW_ON_ERROR));

        $process = new Process([
            'python3',
            \dirname(__DIR__, 3).'/tools/session-storage-audit.py',
            '--project-final',
            $this->projectDir.'/.hatfield',
        ]);
        $process->setTimeout(10.0);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('SCOPE scope=parent files=1 records=7', $output);
        $this->assertStringContainsString('SCOPE scope=child files=1 records=6', $output);
        $this->assertStringContainsString('STATE_JSON scope=parent files=1 total_bytes=', $output);
        $this->assertStringContainsString('STATE_JSON scope=child files=1 total_bytes=', $output);
        $this->assertStringContainsString('OPERATIONAL scope=parent row=state rows=1', $output);
        $this->assertStringContainsString('OPERATIONAL scope=parent row=tool rows=1', $output);
        $this->assertStringContainsString('OPERATIONAL scope=parent row=human rows=1', $output);
        $this->assertStringContainsString('OPERATIONAL scope=child row=tool rows=0 logical_scalar_bytes=0 unsupported_shapes=1', $output);
        $this->assertStringContainsString('EVENT scope=parent type=tool_execution_end records=1', $output);
        $this->assertStringContainsString('FIELD scope=parent type=llm_step_completed path=payload.text present=1', $output);
        $this->assertStringContainsString('PROJECTED_EVENT scope=parent type=tool_execution_end records=1', $output);
        $this->assertStringContainsString('PROJECTED_FIELD scope=parent type=llm_step_completed path=payload.assistant_message present=1', $output);
        $this->assertStringNotContainsString('PROJECTED_FIELD scope=parent type=llm_step_completed path=payload.text', $output);
        $this->assertStringNotContainsString('PROJECTED_FIELD scope=parent type=llm_step_completed path=payload.tool_calls_count', $output);
        $this->assertStringNotContainsString('PROJECTED_EVENT scope=parent type=message_end', $output);
        $this->assertStringNotContainsString('PROJECTED_EVENT scope=parent type=tool_call_result_received', $output);
        $this->assertStringNotContainsString('PROJECTED_EVENT scope=parent type=stale_result_ignored', $output);
        foreach (['run-id-sentinel', 'step-id-sentinel', 'key-sentinel', 'tool-id-sentinel', 'tool-output-sentinel', 'assistant-text-sentinel', 'filename-sentinel', 'child-prompt-sentinel', 'batch-sentinel', 'question-sentinel', 'child-id-sentinel', 'unsupported-shape'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, string $type, array $payload = []): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => 'run-id-sentinel',
            'seq' => $seq,
            'turn_no' => 1,
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /** @param list<array<string, mixed>> $events */
    private function jsonl(array $events): string
    {
        return implode('', array_map(
            static fn (array $event): string => json_encode($event, \JSON_THROW_ON_ERROR)."\n",
            $events,
        ));
    }
}
