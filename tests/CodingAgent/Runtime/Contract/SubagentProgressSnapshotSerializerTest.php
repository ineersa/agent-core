<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Contract;

use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Runtime\DTO\SubagentProgressSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Symfony Serializer produces and consumes canonical
 * subagent_progress payloads; Validator rejects invalid fixed fields.
 */
final class SubagentProgressSnapshotSerializerTest extends TestCase
{
    #[DataProvider('cachePercentageWireCases')]
    public function testCachePercentageSurvivesJsonlRoundTrip(?float $percentage): void
    {
        $builder = new SubagentProgressSnapshotBuilder();
        $summary = new SubagentChildProgressSummary(model: 'test/model', reasoning: 'medium', cacheReadHitPercentage: $percentage);
        $snapshot = $builder->singleFromChildTurn(agentName: 'scout', artifactId: 'a1', agentRunId: 'r1', taskSummary: 'Task', childTurnNo: 1, elapsedMs: 0, enrichment: $summary);
        $payload = SubagentProgressSerializerTestSupport::normalizer()->normalize($snapshot, null, [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
        $event = JsonlCodec::decodeEvent(JsonlCodec::encodeEvent(new RuntimeEvent(
            RuntimeEventTypeEnum::ToolExecutionOutputDelta->value, 'parent', 1, ['tool_call_id' => 'call1', 'subagent_progress' => $payload],
        )));
        $decoded = SubagentProgressSerializerTestSupport::denormalizer()->denormalize($event->payload['subagent_progress'], SubagentProgressSnapshotDTO::class);
        $this->assertInstanceOf(SubagentProgressSnapshotDTO::class, $decoded);
        $actual = $decoded->cacheReadHitPercentage;
        $this->assertSame($percentage, null === $actual ? null : (float) $actual);
    }

    public static function cachePercentageWireCases(): iterable
    {
        yield 'zero hits' => [0.0];
        yield 'all hits' => [100.0];
        yield 'fractional hits' => [93.5];
        yield 'missing telemetry' => [null];
    }

    public function testCachePercentageRejectsNumericStrings(): void
    {
        $raw = SubagentProgressSerializerTestSupport::canonicalSingleWire();
        $raw['cache_read_hit_percentage'] = '100';
        $this->expectException(SerializerExceptionInterface::class);
        $this->expectExceptionMessage('cacheReadHitPercentage');
        SubagentProgressSerializerTestSupport::denormalizer()->denormalize($raw, SubagentProgressSnapshotDTO::class);
    }

    public function testNormalizeMatchesCanonicalKeysAndOmissions(): void
    {
        $builder = new SubagentProgressSnapshotBuilder();
        $snapshot = $builder->singleFromChildTurn(
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

        $this->assertArrayNotHasKey('mode', $payload);
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

    public function testDenormalizeSuppliesCounterDefaults(): void
    {
        $denormalizer = SubagentProgressSerializerTestSupport::denormalizer();
        $single = $denormalizer->denormalize([
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => 'a1',
            'agent_run_id' => 'r1',
            'task_summary' => 'Task',
            'model' => 'test/model',
            'reasoning' => 'medium',
            'turn_no' => 1,
            'elapsed_ms' => 100,
        ], SubagentProgressSnapshotDTO::class);
        $this->assertInstanceOf(SubagentProgressSnapshotDTO::class, $single);
        $this->assertSame('scout', $single->agentName);

        $this->assertSame(0, $single->toolCount);
        $this->assertSame(0, $single->inputTokens);
        $this->assertSame([], $single->recentTools);
    }

    public function testValidatorRejectsBlankStatus(): void
    {
        $snapshot = SubagentProgressSerializerTestSupport::denormalizer()->denormalize([
            'status' => '',
            'agent_name' => 'scout',
            'elapsed_ms' => 0,
            'artifact_id' => 'a',
            'agent_run_id' => 'r',
            'task_summary' => 't',
            'model' => 'test/model',
            'reasoning' => 'medium',
            'turn_no' => 0,
        ], SubagentProgressSnapshotDTO::class);

        $violations = SubagentProgressSerializerTestSupport::validator()->validate($snapshot);
        $this->assertGreaterThan(0, $violations->count());
        $this->expectException(ValidationFailedException::class);
        throw new ValidationFailedException($snapshot, $violations);
    }
}
