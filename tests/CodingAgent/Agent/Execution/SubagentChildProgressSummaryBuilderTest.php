<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubagentChildProgressSummaryBuilder::class)]
final class SubagentChildProgressSummaryBuilderTest extends TestCase
{
    public function testFromDeferredProjectionMapsLifecycleFields(): void
    {
        $builder = new SubagentChildProgressSummaryBuilder();
        $summary = $builder->fromDeferredProjection(
            new DeferredChildRunLifecycleProjectionDTO(
                childStatus: RunStatus::Running,
                childTurnNo: 2,
                lastCommittedSeq: 4,
                model: 'deepseek/deepseek-v4-flash',
                reasoning: 'high',
                toolCount: 2,
                llmStepCount: 2,
                inputTokens: 35000,
                latestInputTokens: 25000,
                outputTokens: 14000,
                reasoningTokens: 584000,
                totalTokens: 633000,
                cost: 0.0104,
                contextWindow: 128000,
                recentTools: ['read: path="a.php"', 'edit: path="b.php"'],
                assistantExcerpt: 'Found the rendering path',
                activeToolLine: 'bash: command="ls"',
            ),
            'agent_summary',
        );

        $this->assertSame(2, $summary->toolCount);
        $this->assertSame(2, $summary->llmStepCount);
        $this->assertSame(35000, $summary->inputTokens);
        $this->assertSame(25000, $summary->latestInputTokens);
        $this->assertSame('deepseek/deepseek-v4-flash', $summary->model);
        $this->assertSame('high', $summary->reasoning);
        $this->assertSame(128000, $summary->contextWindow);
        $this->assertSame(0.0104, $summary->cost);
        $this->assertSame(['read: path="a.php"', 'edit: path="b.php"'], $summary->recentTools);
        $this->assertSame('bash: command="ls"', $summary->activeToolLine);
        $this->assertNotNull($summary->artifactPath);

        $snapshot = (new SubagentProgressSnapshotBuilder())->singleRunningFromChildTurn(
            agentName: 'scout',
            artifactId: 'agent_summary',
            agentRunId: 'child-run-1',
            taskSummary: 'summarize',
            childTurnNo: 2,
            elapsedMs: 100,
            enrichment: $summary,
        );
        $fields = SubagentProgressSerializerTestSupport::normalizer()->normalize($snapshot);
        $this->assertSame(35000, $fields['input_tokens']);
        $this->assertSame(2, $fields['llm_step_count']);
        $this->assertSame(25000, $fields['latest_input_tokens']);
        $this->assertSame('deepseek/deepseek-v4-flash', $fields['model']);
        $this->assertSame('high', $fields['reasoning']);
        $this->assertSame(128000, $fields['context_window']);
    }

    public function testFromLaunchIdentitySeedsModelReasoningAndArtifactPath(): void
    {
        $builder = new SubagentChildProgressSummaryBuilder();
        $summary = $builder->fromLaunchIdentity('test/model', 'medium', 'agent_seed');
        $this->assertSame('test/model', $summary->model);
        $this->assertSame('medium', $summary->reasoning);
        $this->assertSame(0, $summary->toolCount);
        $this->assertSame([], $summary->recentTools);
        $this->assertNotNull($summary->artifactPath);
    }
}
