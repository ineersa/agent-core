<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredPendingToolCallRowDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Doctrine JSON boundary for deferred child lifecycle projections.
 *
 * Thesis: repository Serializer path preserves canonical keys/null omission;
 * corrupt rows fail closed without partial objects.
 */
final class DeferredChildRunLifecycleProjectionSerializerTest extends IsolatedKernelTestCase
{
    public function testFullProjectionRoundTripsCanonicalShape(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::WaitingHuman,
            childTurnNo: 4,
            lastCommittedSeq: 12,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
            errorMessage: 'boom',
            assistantResultText: 'hello world',
            assistantExcerpt: 'hello',
            toolCount: 2,
            llmStepCount: 3,
            inputTokens: 100,
            latestInputTokens: 40,
            contextWindow: 128000,
            outputTokens: 20,
            reasoningTokens: 5,
            totalTokens: 125,
            cost: 0.0123,
            provider: 'deepseek',
            recentTools: ['read path.php', 'edit path.php'],
            activeToolLine: 'bash ls',
            pendingToolCalls: [
                'tc1' => new DeferredPendingToolCallRowDTO(name: 'bash', displayLine: 'bash ls'),
            ],
        );

        $wire = $repo->encodeChildLifecycleProjection($projection);

        $this->assertSame('deepseek/deepseek-v4-flash', $wire['model']);
        $this->assertSame('medium', $wire['reasoning']);
        $this->assertSame('waiting_human', $wire['child_status']);
        $this->assertSame(4, $wire['child_turn_no']);
        $this->assertSame(12, $wire['last_committed_seq']);
        $this->assertSame('boom', $wire['error_message']);
        $this->assertSame('hello world', $wire['assistant_result_text']);
        $this->assertSame('hello', $wire['assistant_excerpt']);
        $this->assertSame(2, $wire['tool_count']);
        $this->assertSame(3, $wire['llm_step_count']);
        $this->assertSame(100, $wire['input_tokens']);
        $this->assertSame(40, $wire['latest_input_tokens']);
        $this->assertSame(128000, $wire['context_window']);
        $this->assertSame(20, $wire['output_tokens']);
        $this->assertSame(5, $wire['reasoning_tokens']);
        $this->assertSame(125, $wire['total_tokens']);
        $this->assertSame(0.0123, $wire['cost']);
        $this->assertSame('deepseek', $wire['provider']);
        $this->assertSame(['read path.php', 'edit path.php'], $wire['recent_tools']);
        $this->assertSame('bash ls', $wire['active_tool']);
        $this->assertSame(
            ['tc1' => ['name' => 'bash', 'display_line' => 'bash ls']],
            $wire['pending_tool_calls'],
        );

        $roundTrip = $repo->decodeChildLifecycleProjection($wire);
        $this->assertEquals($projection, $roundTrip);
        $this->assertSame($wire, $repo->encodeChildLifecycleProjection($roundTrip));
    }

    public function testOptionalNullsAreOmittedOnWrite(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: 0,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
            errorMessage: '',
            assistantResultText: '',
            assistantExcerpt: '',
            contextWindow: 0,
            cost: 0.0,
            provider: '',
            activeToolLine: '',
        );

        $wire = $repo->encodeChildLifecycleProjection($projection);
        $this->assertArrayNotHasKey('error_message', $wire);
        $this->assertArrayNotHasKey('assistant_result_text', $wire);
        $this->assertArrayNotHasKey('assistant_excerpt', $wire);
        $this->assertArrayNotHasKey('context_window', $wire);
        $this->assertArrayNotHasKey('cost', $wire);
        $this->assertArrayNotHasKey('provider', $wire);
        $this->assertArrayNotHasKey('active_tool', $wire);
        $this->assertSame('deepseek/deepseek-v4-flash', $wire['model']);
        $this->assertSame('medium', $wire['reasoning']);
        $this->assertSame('running', $wire['child_status']);
        $this->assertSame([], $wire['pending_tool_calls']);
        $this->assertSame([], $wire['recent_tools']);
    }

    public function testInvalidChildStatusFailsAtBoundary(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentChildRepository::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid deferred child lifecycle projection');
        $repo->decodeChildLifecycleProjection([
            'child_status' => 'not-a-status',
            'child_turn_no' => 1,
            'last_committed_seq' => 1,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
        ]);
    }

    public function testMalformedPendingToolCallFailsAtBoundary(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentChildRepository::class);

        try {
            $repo->decodeChildLifecycleProjection([
                'child_status' => 'running',
                'child_turn_no' => 1,
                'last_committed_seq' => 1,
                'model' => 'deepseek/deepseek-v4-flash',
                'reasoning' => 'medium',
                'pending_tool_calls' => [
                    'tc1' => ['name' => '', 'display_line' => ''],
                ],
            ]);
            $this->fail('Expected domain validation failure for blank pending tool call fields.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Invalid deferred child lifecycle projection', $exception->getMessage());
            $this->assertInstanceOf(ValidationFailedException::class, $exception->getPrevious());
        }
    }

    public function testStandaloneSerializerRoundTripMatchesRepository(): void
    {
        // Non-kernel path still hydrates nested pending rows via PhpDoc + ArrayDenormalizer.
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 1,
            lastCommittedSeq: 1,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
            inputTokens: 1,
            pendingToolCalls: [
                'tc1' => new DeferredPendingToolCallRowDTO(name: 'bash', displayLine: 'bash ls'),
            ],
        );

        /** @var array<string, mixed> $wire */
        $wire = $serializer->normalize($projection, null, [
            \Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);
        $this->assertSame(
            ['tc1' => ['name' => 'bash', 'display_line' => 'bash ls']],
            $wire['pending_tool_calls'],
        );

        $decoded = $serializer->denormalize($wire, DeferredChildRunLifecycleProjectionDTO::class);
        $this->assertInstanceOf(DeferredChildRunLifecycleProjectionDTO::class, $decoded);
        $this->assertCount(0, $validator->validate($decoded));
        $this->assertEquals($projection, $decoded);
    }
}
