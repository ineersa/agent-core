<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live controller proof: fork tool enters deferred lifecycle and completes once.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class ForkDeferredLiveE2eTest extends ControllerE2eTestCase
{
    private const CHILD_REPLY = 'FORK_CHILD_DONE';

    public function testForkToolDeferredCompletionViaLiveController(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_fork_live_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:fork-deferred-v2] Call tool fork exactly once with JSON arguments {"task":"Reply with exactly '.self::CHILD_REPLY.' only. No other tools."}. Do not call any tool except fork.',
            ],
        ]);

        $events = $this->collectEventsUntilDeferredForkCompleted($this->liveLlmDeferredForkToolWaitTimeout());
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $this->assertArrayHasKey('tool_execution.started', $byType, $this->collectDiagnostics($events));
        $this->assertSame(
            'fork',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            $this->collectDiagnostics($events),
        );

        $forkCallId = (string) ($byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? '');
        $this->assertNotEmpty($forkCallId);

        $this->assertArrayNotHasKey('tool_execution.failed', $byType, 'fork tool must not fail. '.$this->collectDiagnostics($events));

        $completed = array_values(array_filter(
            $byType['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? '') === $forkCallId,
        ));
        $this->assertCount(1, $completed, 'Exactly one matching fork tool completion expected. '.$this->collectDiagnostics($events));
        $this->assertFalse($completed[0]['payload']['is_error'] ?? true, $this->collectDiagnostics($events));

        $completedPayload = $completed[0]['payload'] ?? [];
        $resultText = (string) ($completedPayload['result'] ?? '');
        if ('' === $resultText) {
            $resultText = json_encode($completedPayload, \JSON_THROW_ON_ERROR);
        }
        $this->assertStringContainsString(self::CHILD_REPLY, $resultText, 'Fork completion must include child reply token. '.$this->collectDiagnostics($events));
    }

    /**
     * Deferred fork is two sequential LLM calls plus multi-hop Messenger delivery.
     * Align with SubagentParallelLiveE2eTest multi-LLM budget (25s); early-exit collector
     * returns as soon as completion arrives.
     */
    protected function liveLlmDeferredForkToolWaitTimeout(): float
    {
        return 25.0;
    }

    /**
     * Live HttpClient defaults to 5s under APP_ENV=test; multi-LLM deferred paths need more.
     *
     * @return array<string, string>
     */
    protected function controllerSubprocessEnv(): array
    {
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '60'];
    }

    protected function controllerExtraArgs(): array
    {
        return ['--tools=fork'];
    }

    protected function tempDirPrefix(): string
    {
        return 'fork-deferred-live';
    }

    /**
     * Deferred fork can complete after parent run.completed; wait by tool_call_id only.
     *
     * Local to this live smoke — does not change shared ControllerE2eTestCase collectors.
     *
     * @return list<array<string, mixed>>
     */
    private function collectEventsUntilDeferredForkCompleted(float $timeout): array
    {
        $events = [];
        $targetToolCallIds = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                $type = $event['type'] ?? '';
                $payload = $event['payload'] ?? [];
                if (!\is_array($payload)) {
                    $payload = [];
                }

                if ('tool_execution.started' === $type
                    && 'fork' === ($payload['tool_name'] ?? null)
                    && isset($payload['tool_call_id'])
                ) {
                    $targetToolCallIds[(string) $payload['tool_call_id']] = true;
                }

                if ('tool_execution.completed' === $type
                    && isset($payload['tool_call_id'])
                    && isset($targetToolCallIds[(string) $payload['tool_call_id']])
                ) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }
}
