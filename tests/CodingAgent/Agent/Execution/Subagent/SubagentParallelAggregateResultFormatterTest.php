<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class SubagentParallelAggregateResultFormatterTest extends TestCase
{
    public function testCombinedResponseLimitPreservesBoundaryAndListsAllArtifactsAboveIt(): void
    {
        $formatter = new SubagentParallelAggregateResultFormatter();
        $first = $this->item(1, str_repeat('é', 25000));
        $second = $this->item(2, 'tail');
        $result = new ChildRunBatchSupervisionResultDTO(items: [$second, $first]);
        $base = $formatter->formatSuccess($result);
        $second->message .= str_repeat('é', 50000 - u($base)->length());
        $boundary = $formatter->formatSuccess($result);
        $this->assertSame(50000, u($boundary)->length());
        $this->assertStringContainsString($first->message, $boundary);
        $this->assertStringContainsString($second->message, $boundary);

        $second->message .= 'x';
        $short = $formatter->formatSuccess($result);
        $this->assertStringContainsString('exceeds 50,000 characters', $short);
        $this->assertStringContainsString("#1 completed\nArtifact: agent_1\n#2 completed\nArtifact: agent_2", $short);
        $this->assertStringContainsString('agent_retrieve', $short);
        $this->assertStringNotContainsString('é', $short);
        $this->assertLessThan(50000, u($short)->length());
    }

    public function testOversizedMixedReportRetainsStatusesAndArtifactsWithoutHandoffs(): void
    {
        $formatter = new SubagentParallelAggregateResultFormatter();
        $first = $this->item(1, str_repeat('x', 50001));
        $second = $this->item(2, 'Failure detail');
        $second->artifactStatus = AgentArtifactStatusEnum::Failed;
        $result = new ChildRunBatchSupervisionResultDTO(items: [$second, $first]);
        $short = $formatter->formatReport($result);
        $this->assertStringContainsString('Inline handoffs omitted', $short);
        $this->assertStringContainsString("#1 completed\nArtifact: agent_1\n#2 failed\nArtifact: agent_2", $short);
        $this->assertStringNotContainsString('Failure detail', $short);
        $this->assertStringNotContainsString($first->message, $short);
    }

    private function item(int $index, string $message): ChildRunBatchItemSnapshotDTO
    {
        return new ChildRunBatchItemSnapshotDTO(
            identity: new ChildRunIdentityDTO(
                parentRunId: 'parent',
                childRunId: 'child-'.$index,
                artifactId: 'agent_'.$index,
                displayName: 'scout',
                taskSummary: 'Inspect code',
                artifactKind: AgentArtifactKindEnum::Subagent,
                batchIndex: $index,
            ),
            terminal: true,
            artifactStatus: AgentArtifactStatusEnum::Completed,
            message: $message,
        );
    }
}
