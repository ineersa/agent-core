<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller-subprocess proof for cancel during an active tool,
 * then follow-up without restart.
 *
 * The previous bash-then-cancel journey was deleted as a scheduling race. This
 * replacement waits for tool_execution.started before cancel, then asserts
 * parent terminalization and a successful follow_up on the same controller.
 *
 * Deferred fork/subagent reserved-child cancel-before-start remains covered by
 * DeferredSubagentBatchLifecycleTest and StartRunHandlerTest; this case owns
 * the real controller JSONL + Messenger cancel/follow-up path.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayCancelDuringBashThenFollowUpTest extends ControllerReplayE2eTestCase
{
    private const string TOOL_CALL_ID = 'call_bash_blocker_1';
    private const string FOLLOW_UP_MARKER = '[controller-replay:cancel-follow-up] ack';

    public function testCancelDuringActiveBashThenFollowUpWithoutRestart(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Run bash sleep 1 once. Do not call any other tool.',
            ],
        ]);

        $preCancel = $this->collectEventsUntil(
            null,
            8.0,
            static fn (array $event): bool => ($event['type'] ?? '') === 'tool_execution.started'
                && ($event['payload']['tool_call_id'] ?? null) === self::TOOL_CALL_ID,
        );
        $preByType = $this->indexByType($preCancel);

        $this->assertStartRunAcked($preCancel, $startCmdId);
        $this->assertArrayHasKey('run.started', $preByType, $this->collectDiagnostics($preCancel));
        $this->runId = (string) ($preByType['run.started'][0]['runId']
            ?? $preByType['run.started'][0]['payload']['runId']
            ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must include runId');

        $this->assertArrayHasKey(
            'tool_execution.started',
            $preByType,
            'bash tool must start before cancel. '.$this->collectDiagnostics($preCancel),
        );
        $this->assertSame('bash', $preByType['tool_execution.started'][0]['payload']['tool_name'] ?? null);
        $this->assertSame(self::TOOL_CALL_ID, $preByType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null);

        $cancelCmdId = 'cmd_cancel_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $cancelCmdId,
            'type' => 'cancel',
            'runId' => $this->runId,
            'payload' => [],
        ]);

        $cancelEvents = $this->collectEventsUntil('run.cancelled', 8.0);
        $allCancel = array_merge($preCancel, $cancelEvents);
        $cancelByType = $this->indexByType($allCancel);

        $this->assertTrue(
            $this->foundAck($cancelEvents, $cancelCmdId),
            'cancel must be acked. '.$this->collectDiagnostics($cancelEvents),
        );
        $this->assertArrayHasKey(
            'run.cancelled',
            $cancelByType,
            'Parent must terminalize to run.cancelled. '.$this->collectDiagnostics($allCancel),
        );
        $this->assertArrayNotHasKey('run.failed', $cancelByType, $this->collectDiagnostics($allCancel));

        $followUpCmdId = 'cmd_fu_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => self::FOLLOW_UP_MARKER,
            ],
        ]);

        $followUpEvents = $this->collectEventsUntil('run.completed', 5.0);
        $followUpByType = $this->indexByType($followUpEvents);

        $this->assertTrue(
            $this->foundAck($followUpEvents, $followUpCmdId),
            'Expected command.ack for follow_up. '.$this->collectDiagnostics($followUpEvents),
        );
        $this->assertArrayNotHasKey(
            'command.rejected',
            $followUpByType,
            'follow_up after cancel must not be rejected. '.$this->collectDiagnostics($followUpEvents),
        );
        $this->assertArrayNotHasKey('run.failed', $followUpByType, $this->collectDiagnostics($followUpEvents));
        $this->assertArrayHasKey(
            'run.completed',
            $followUpByType,
            'Follow-up after cancel must complete without restart. '.$this->collectDiagnostics($followUpEvents),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertDirectoryExists($sessionDir);
        $eventsJsonl = $sessionDir.'/events.jsonl';
        $this->assertFileExists($eventsJsonl);
        $jsonl = (string) file_get_contents($eventsJsonl);
        $this->assertStringContainsString('agent_end', $jsonl);
        $this->assertStringContainsString('"reason":"cancelled"', $jsonl);
        $this->assertStringContainsString('agent_command_applied', $jsonl);
        $this->assertStringContainsString('"kind":"follow_up"', $jsonl);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-cancel-followup';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $fixturePath = __DIR__.'/fixtures/controller-bash-blocker.json';
        $bashFixture = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($bashFixture);

        $followUpFixture = [
            '$schema' => 'Synthetic controller replay — follow_up after cancel',
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Absorb the post-cancel follow_up LLM turn.',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [
                ['type' => 'text', 'content' => 'follow-up after cancel ok'],
            ],
        ];

        return [$bashFixture, $followUpFixture];
    }
}
