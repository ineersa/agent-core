<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Contract;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressParallelChildReportDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Thesis: Symfony Serializer + DiscriminatorMap produce/consume canonical
 * subagent_progress payloads; Validator rejects invalid fixed fields.
 */
final class SubagentProgressSnapshotSerializerTest extends TestCase
{
    public function testNormalizeSingleMatchesHistoricalKeysAndOmissions(): void
    {
        $builder = new SubagentProgressSnapshotBuilder();
        $snapshot = $builder->singleRunningFromChildTurn(
            agentName: 'scout',
            artifactId: 'agent_abc',
            agentRunId: 'child-run-1',
            taskSummary: 'Inspect TUI',
            childTurnNo: 2,
            elapsedMs: 1500,
            enrichment: new SubagentChildProgressSummary(
                model: 'test/model',
                reasoning: 'medium',
                toolCount: 3,
                llmStepCount: 2,
                inputTokens: 100,
                latestInputTokens: 80,
                contextWindow: 128000,
                outputTokens: 40,
                reasoningTokens: 10,
                totalTokens: 150,
                cost: 0.01,
                recentTools: ['read: path="a.php"'],
            ),
        );

        $payload = SubagentProgressSerializerTestSupport::normalizer()->normalize($snapshot, null, [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);

        $this->assertSame('single', $payload['mode']);
        $this->assertSame('running', $payload['status']);
        $this->assertSame('scout', $payload['agent_name']);
        $this->assertSame('agent_abc', $payload['artifact_id']);
        $this->assertSame('child-run-1', $payload['agent_run_id']);
        $this->assertSame('Inspect TUI', $payload['task_summary']);
        $this->assertSame(2, $payload['turn_no']);
        $this->assertSame(1500, $payload['elapsed_ms']);
        $this->assertSame(3, $payload['tool_count']);
        $this->assertSame(2, $payload['llm_step_count']);
        $this->assertSame(100, $payload['input_tokens']);
        $this->assertSame(80, $payload['latest_input_tokens']);
        $this->assertSame(40, $payload['output_tokens']);
        $this->assertSame(10, $payload['reasoning_tokens']);
        $this->assertSame(150, $payload['total_tokens']);
        $this->assertSame(['read: path="a.php"'], $payload['recent_tools']);
        $this->assertSame(0.01, $payload['cost']);
        $this->assertSame('test/model', $payload['model']);
        $this->assertSame('medium', $payload['reasoning']);
        $this->assertSame(128000, $payload['context_window']);
        $this->assertArrayNotHasKey('provider', $payload);
        $this->assertArrayNotHasKey('active_tool', $payload);
        $this->assertArrayNotHasKey('children', $payload);
    }

    public function testNormalizeParallelIncludesChildrenAndAggregateZeros(): void
    {
        $builder = new SubagentProgressSnapshotBuilder();
        $snapshot = $builder->parallelSnapshot(
            reports: [
                'c1' => new SubagentProgressParallelChildReportDTO(
                    index: 1,
                    agentName: 'reviewer',
                    task: 'Review',
                    artifactId: 'a1',
                    agentRunId: 'r1',
                    terminal: true,
                    status: AgentArtifactStatusEnum::Completed,
                ),
                'c2' => new SubagentProgressParallelChildReportDTO(
                    index: 2,
                    agentName: 'scout',
                    task: 'Scout',
                    artifactId: 'a2',
                    agentRunId: 'r2',
                    terminal: false,
                    status: null,
                ),
            ],
            activeTurns: ['r1' => 3, 'r2' => 1],
            elapsedMs: 9000,
            aggregateStatus: 'running',
        );

        $payload = SubagentProgressSerializerTestSupport::normalizer()->normalize($snapshot, null, [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);

        $this->assertSame('parallel', $payload['mode']);
        $this->assertSame(1, $payload['completed_count']);
        $this->assertSame(2, $payload['total_count']);
        $this->assertSame(9000, $payload['elapsed_ms']);
        $this->assertSame(0, $payload['tool_count']);
        $this->assertSame(0, $payload['input_tokens']);
        $this->assertArrayNotHasKey('cost', $payload);
        $this->assertCount(2, $payload['children']);
        $this->assertSame('reviewer', $payload['children'][0]['agent_name']);
        $this->assertSame('completed', $payload['children'][0]['status']);
        $this->assertSame('scout', $payload['children'][1]['agent_name']);
        $this->assertSame('running', $payload['children'][1]['status']);
    }

    public function testDenormalizeRoundTripAndRejectsInvalidMode(): void
    {
        $denormalizer = SubagentProgressSerializerTestSupport::denormalizer();
        $single = $denormalizer->denormalize([
            'mode' => 'single',
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => 'a1',
            'agent_run_id' => 'r1',
            'task_summary' => 'Task',
            'turn_no' => 1,
            'elapsed_ms' => 100,
        ], SubagentProgressSnapshotInterface::class);
        $this->assertInstanceOf(SubagentProgressSingleSnapshotDTO::class, $single);
        $this->assertSame('scout', $single->agentName);

        $parallel = $denormalizer->denormalize([
            'mode' => 'parallel',
            'status' => 'running',
            'completed_count' => 0,
            'total_count' => 1,
            'elapsed_ms' => 10,
            'children' => [
                [
                    'index' => 1,
                    'label' => 'Step 1',
                    'agent_name' => 'scout',
                    'status' => 'running',
                    'artifact_id' => 'a1',
                    'agent_run_id' => 'r1',
                    'task_summary' => 'T',
                    'turn_no' => 0,
                ],
            ],
            'tool_count' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'total_tokens' => 0,
        ], SubagentProgressSnapshotInterface::class);
        $this->assertInstanceOf(SubagentProgressParallelSnapshotDTO::class, $parallel);
        $this->assertCount(1, $parallel->children);

        $this->expectException(SerializerExceptionInterface::class);
        $denormalizer->denormalize(['mode' => 'weird', 'status' => 'running'], SubagentProgressSnapshotInterface::class);
    }

    public function testValidatorRejectsBlankStatus(): void
    {
        $snapshot = SubagentProgressSerializerTestSupport::denormalizer()->denormalize([
            'mode' => 'single',
            'status' => '',
            'agent_name' => 'scout',
            'elapsed_ms' => 0,
            'artifact_id' => 'a',
            'agent_run_id' => 'r',
            'task_summary' => 't',
            'turn_no' => 0,
        ], SubagentProgressSnapshotInterface::class);

        $violations = SubagentProgressSerializerTestSupport::validator()->validate($snapshot);
        $this->assertGreaterThan(0, $violations->count());
        $this->expectException(ValidationFailedException::class);
        throw new ValidationFailedException($snapshot, $violations);
    }
}
