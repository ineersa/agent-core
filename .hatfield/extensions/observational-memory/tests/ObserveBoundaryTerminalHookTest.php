<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserveBoundaryTerminalHook;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

final class ObserveBoundaryTerminalHookTest extends OmTestCase
{
    #[DataProvider('boundaries')]
    public function testTerminalSemantics(string $status, string $type, array $payload, bool $dispatch): void
    {
        $api = $this->api();
        $api->expects($dispatch ? $this->once() : $this->never())->method('dispatchExtensionAgentJob')->with($this->callback(static function (ExtensionAgentJobRequestDTO $job) use ($payload): bool {
            return ObserveBoundaryTerminalHook::HANDLER_ID === $job->handlerId
                && ['run_id' => 'run-1', 'terminal_end_seq' => 148, 'terminal_status' => $payload['reason'] ?? 'failed'] === $job->payload;
        }));
        (new ObserveBoundaryTerminalHook($api, new NullLogger()))->onAfterTurnCommit(new AfterTurnCommitHookContextDTO('run-1', 2, $status, [new AfterTurnCommitEventSummaryDTO(147, 'tool_batch_committed', ['private' => 'not uploaded']), new AfterTurnCommitEventSummaryDTO(148, $type, $payload)], 0));
    }

    public static function boundaries(): iterable
    {
        yield 'completed' => ['completed', 'agent_end', ['reason' => 'completed'], true];
        yield 'cancelled' => ['cancelled', 'agent_end', ['reason' => 'cancelled'], true];
        yield 'final failure' => ['failed', 'llm_step_failed', ['retryable' => false], true];
        yield 'exhausted' => ['failed', 'llm_step_failed', ['retryable' => true, 'retries_exhausted' => true], true];
        yield 'retrying' => ['failed', 'llm_step_failed', ['retryable' => true], false];
        yield 'intermediate' => ['running', 'tool_batch_committed', [], false];
    }

    #[DataProvider('excluded')]
    public function testExcludedRunsNeverDispatch(bool $child, bool $worktree): void
    {
        $api = $this->api($child, $worktree);
        $api->expects($this->never())->method('dispatchExtensionAgentJob');
        (new ObserveBoundaryTerminalHook($api, new NullLogger()))->onAfterTurnCommit(new AfterTurnCommitHookContextDTO('run-1', 1, 'completed', [new AfterTurnCommitEventSummaryDTO(9, 'agent_end', ['reason' => 'completed'])], 0));
    }

    public static function excluded(): iterable
    {
        yield 'child/fork' => [true, false];
        yield 'linked worktree' => [false, true];
    }

    public function testCheckoutInspectionStaysOutsideCommittedTurnPath(): void
    {
        $api = $this->api();
        $api->expects($this->once())->method('getCwd');
        $api->expects($this->exactly(2))->method('dispatchExtensionAgentJob');
        $hook = new ObserveBoundaryTerminalHook($api, new NullLogger());
        $context = new AfterTurnCommitHookContextDTO('run-1', 1, 'completed', [new AfterTurnCommitEventSummaryDTO(9, 'agent_end', ['reason' => 'completed'])], 0);
        $hook->onAfterTurnCommit($context);
        $hook->onAfterTurnCommit($context);
    }

    public function testDispatchFailureDoesNotFailCommittedTurn(): void
    {
        $api = $this->api();
        $api->method('dispatchExtensionAgentJob')->willThrowException(new \RuntimeException('private'));
        $logger = new TestLogger();
        (new ObserveBoundaryTerminalHook($api, $logger))->onAfterTurnCommit(new AfterTurnCommitHookContextDTO('run-1', 1, 'completed', [new AfterTurnCommitEventSummaryDTO(9, 'agent_end', ['reason' => 'completed'])], 0));
        $this->assertCount(1, $logger->records);
        $this->assertSame('run-1', $logger->records[0]['context']['session_id']);
        $this->assertSame(9, $logger->records[0]['context']['end_seq']);
        $this->assertStringNotContainsString('private', json_encode($logger->records));
    }
}
