<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionFailureReasonEnum;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionResult;
use Ineersa\CodingAgent\Agent\Fork\ForkCompactionSummarizationException;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotCompactor;
use Ineersa\CodingAgent\Compaction\VirtualCompactionOrchestratorInterface;
use Ineersa\CodingAgent\Compaction\VirtualCompactionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForkSnapshotCompactor::class)]
#[CoversClass(ForkCompactionResult::class)]
final class ForkSnapshotCompactorTest extends TestCase
{
    public function testCompactDelegatesToVirtualOrchestratorWithForce(): void
    {
        $messages = [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']])];
        $orchestrator = new RecordingVirtualCompactionOrchestrator(
            new VirtualCompactionResult(
                compactedMessages: $messages,
                compacted: true,
                summaryText: 'summary',
                summarizedCount: 1,
            ),
        );

        $compactor = new ForkSnapshotCompactor($orchestrator);
        $result = $compactor->compact($messages, 'parent-run-1');

        $this->assertTrue($result->compacted);
        $this->assertSame('summary', $result->summaryText);
        $this->assertSame(1, $result->summarizedCount);
        $this->assertSame(['parent-run-1', $messages, true], $orchestrator->lastCall);
    }

    public function testCompactPropagatesOrchestratorFailures(): void
    {
        $orchestrator = new ThrowingVirtualCompactionOrchestrator(
            new ForkCompactionSummarizationException('inner failure', ForkCompactionFailureReasonEnum::PreparationFailed, hint: 'inner hint'),
        );

        $compactor = new ForkSnapshotCompactor($orchestrator);

        $this->expectException(ForkCompactionSummarizationException::class);
        $this->expectExceptionMessage('inner failure');
        $compactor->compact([
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'one']]),
        ], 'parent-run-1');
    }
}

final class RecordingVirtualCompactionOrchestrator implements VirtualCompactionOrchestratorInterface
{
    /** @var array{0:string,1:array,2:bool}|null */
    public ?array $lastCall = null;

    public function __construct(private VirtualCompactionResult $result)
    {
    }

    public function compactForRun(string $runId, array $messages, bool $force = false): VirtualCompactionResult
    {
        $this->lastCall = [$runId, $messages, $force];

        return $this->result;
    }
}

final class ThrowingVirtualCompactionOrchestrator implements VirtualCompactionOrchestratorInterface
{
    public function __construct(private ForkCompactionSummarizationException $exception)
    {
    }

    public function compactForRun(string $runId, array $messages, bool $force = false): VirtualCompactionResult
    {
        throw $this->exception;
    }
}
