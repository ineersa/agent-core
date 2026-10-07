<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\ExecutionOutcomeUnknownHandler;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutionOutcomeUnknownHandlerTest extends TestCase
{
    public static function shellNotices(): iterable
    {
        yield 'current attached shell' => [2, 'shell', 1, 'call', true];
        yield 'older turn' => [1, 'shell', 1, 'call', false];
        yield 'different step' => [2, 'older-shell', 1, 'call', false];
        yield 'older attempt' => [2, 'shell', 0, 'call', false];
        yield 'different call' => [2, 'shell', 1, 'old-call', false];
    }

    #[DataProvider('shellNotices')]
    public function testAttachedShellNoticeRequiresCurrentInvocation(int $turn, string $step, int $attempt, string $call, bool $fails): void
    {
        $state = RunState::queued('1')->with([
            'status' => RunStatus::Running,
            'turnNo' => 2,
            'currentOperation' => new CurrentOperationDTO(2, 'model', 1, 'model-key'),
            'pendingShellToolCalls' => ['call' => true],
            'currentToolCalls' => [new CurrentToolCallDTO(ToolBatchIdentity::fromTurnAndStep(2, 'shell'), 'call', 0, RunOperationalToolCallStatusEnum::Running, 1)],
        ]);
        $notice = new ExecutionOutcomeUnknown('1', $turn, $step, $attempt, hash('sha256', '1|'.$call), 'effect', 'claim');
        $store = $this->createMock(ExecutionOperationStoreInterface::class);
        $store->expects($this->once())->method('unknownNoticePending')->with($notice)->willReturn(true);
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($this->never())->method('load');
        $result = (new ExecutionOutcomeUnknownHandler($store, $batches))->handle($notice, $state);
        $this->assertNotNull($result->nextState);
        $this->assertSame($fails ? RunStatus::Failed : RunStatus::Running, $result->nextState->status);
        $this->assertCount($fails ? 1 : 0, $result->events);
        $this->assertCount(1, $result->postCommitActions);
        $this->assertInstanceOf(ConsumeExecutionUnknownDTO::class, $result->postCommitActions[0]);
        if ($fails) {
            $this->assertSame(ExecutionOutcomeUnknown::ERROR_MESSAGE, $result->nextState->errorMessage);
        } else {
            $this->assertSame($state, $result->nextState);
        }
    }
}
