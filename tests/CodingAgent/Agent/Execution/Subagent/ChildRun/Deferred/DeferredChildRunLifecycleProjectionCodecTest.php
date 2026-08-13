<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredPendingToolCallRowDTO;
use Ineersa\CodingAgent\Tests\Support\DeferredChildRunLifecycleProjectionCodecTestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Doctrine JSON boundary for deferred child lifecycle projections.
 *
 * Thesis: Serializer preserves exact historical on-disk keys/omission rules;
 * corrupt rows fail at the codec boundary without partial objects; validation
 * failures share the domain InvalidArgumentException contract with Serializer errors.
 */
final class DeferredChildRunLifecycleProjectionCodecTest extends TestCase
{
    public function testFullProjectionRoundTripsExactHistoricalShape(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();
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

        $wire = $codec->normalize($projection);

        $this->assertSame([
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
            'child_status' => 'waiting_human',
            'child_turn_no' => 4,
            'last_committed_seq' => 12,
            'error_message' => 'boom',
            'assistant_result_text' => 'hello world',
            'assistant_excerpt' => 'hello',
            'tool_count' => 2,
            'llm_step_count' => 3,
            'input_tokens' => 100,
            'latest_input_tokens' => 40,
            'context_window' => 128000,
            'output_tokens' => 20,
            'reasoning_tokens' => 5,
            'total_tokens' => 125,
            'cost' => 0.0123,
            'provider' => 'deepseek',
            'recent_tools' => ['read path.php', 'edit path.php'],
            'active_tool' => 'bash ls',
            'pending_tool_calls' => [
                'tc1' => ['name' => 'bash', 'displayLine' => 'bash ls'],
            ],
        ], $wire);

        $roundTrip = $codec->denormalize($wire);
        $this->assertEquals($projection, $roundTrip);
        $this->assertSame($wire, $codec->normalize($roundTrip));
    }

    public function testMinimalHistoricalRowHydratesAndOmitsOptionalKeysOnWrite(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();
        $historical = [
            'child_status' => 'running',
            'child_turn_no' => 1,
            'last_committed_seq' => 1,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
            'input_tokens' => 1,
        ];

        $dto = $codec->denormalize($historical);
        $this->assertSame(RunStatus::Running, $dto->childStatus);
        $this->assertSame(1, $dto->childTurnNo);
        $this->assertSame(1, $dto->lastCommittedSeq);
        $this->assertSame(1, $dto->inputTokens);
        $this->assertSame(0, $dto->toolCount);
        $this->assertSame([], $dto->pendingToolCalls);
        $this->assertNull($dto->errorMessage);
        $this->assertNull($dto->cost);
        $this->assertSame(0, $dto->contextWindow);

        $rewritten = $codec->normalize($dto);
        $this->assertArrayNotHasKey('error_message', $rewritten);
        $this->assertArrayNotHasKey('assistant_result_text', $rewritten);
        $this->assertArrayNotHasKey('assistant_excerpt', $rewritten);
        $this->assertArrayNotHasKey('context_window', $rewritten);
        $this->assertArrayNotHasKey('cost', $rewritten);
        $this->assertSame('deepseek/deepseek-v4-flash', $rewritten['model']);
        $this->assertSame('medium', $rewritten['reasoning']);
        $this->assertArrayNotHasKey('provider', $rewritten);
        $this->assertArrayNotHasKey('active_tool', $rewritten);
        $this->assertSame('running', $rewritten['child_status']);
        $this->assertSame(1, $rewritten['input_tokens']);
        $this->assertSame([], $rewritten['pending_tool_calls']);
        $this->assertSame([], $rewritten['recent_tools']);
    }

    public function testZeroCostAndEmptyOptionalStringsAreOmitted(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();
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

        $wire = $codec->normalize($projection);
        $this->assertArrayNotHasKey('error_message', $wire);
        $this->assertArrayNotHasKey('assistant_result_text', $wire);
        $this->assertArrayNotHasKey('assistant_excerpt', $wire);
        $this->assertArrayNotHasKey('context_window', $wire);
        $this->assertArrayNotHasKey('cost', $wire);
        $this->assertSame('deepseek/deepseek-v4-flash', $wire['model']);
        $this->assertSame('medium', $wire['reasoning']);
        $this->assertArrayNotHasKey('provider', $wire);
        $this->assertArrayNotHasKey('active_tool', $wire);
    }

    public function testInvalidChildStatusFailsAtBoundary(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid deferred child lifecycle projection');
        $codec->denormalize([
            'child_status' => 'not-a-status',
            'child_turn_no' => 1,
            'last_committed_seq' => 1,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
        ]);
    }

    public function testHistoricalDisplayLineAliasHydratesAndRewritesCanonicalKey(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();
        $historical = [
            'child_status' => 'running',
            'child_turn_no' => 2,
            'last_committed_seq' => 3,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
            'pending_tool_calls' => [
                'tc-alias' => [
                    'name' => 'bash',
                    'display_line' => 'bash ls -la',
                ],
            ],
        ];

        $dto = $codec->denormalize($historical);
        $this->assertArrayHasKey('tc-alias', $dto->pendingToolCalls);
        $this->assertInstanceOf(DeferredPendingToolCallRowDTO::class, $dto->pendingToolCalls['tc-alias']);
        $this->assertSame('bash', $dto->pendingToolCalls['tc-alias']->name);
        $this->assertSame('bash ls -la', $dto->pendingToolCalls['tc-alias']->displayLine);

        $wire = $codec->normalize($dto);
        $this->assertSame(
            ['tc-alias' => ['name' => 'bash', 'displayLine' => 'bash ls -la']],
            $wire['pending_tool_calls'],
        );
        $this->assertArrayNotHasKey('display_line', $wire['pending_tool_calls']['tc-alias']);

        // Caller input must not be mutated by the boundary rewrite.
        $this->assertSame('bash ls -la', $historical['pending_tool_calls']['tc-alias']['display_line']);
        $this->assertArrayNotHasKey('displayLine', $historical['pending_tool_calls']['tc-alias']);
    }

    public function testCanonicalDisplayLineWinsOverHistoricalAlias(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();
        $dto = $codec->denormalize([
            'child_status' => 'running',
            'child_turn_no' => 1,
            'last_committed_seq' => 1,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
            'pending_tool_calls' => [
                'tc1' => [
                    'name' => 'edit',
                    'displayLine' => 'edit path.php',
                    'display_line' => 'should-not-win',
                ],
            ],
        ]);

        $this->assertSame('edit path.php', $dto->pendingToolCalls['tc1']->displayLine);
        $wire = $codec->normalize($dto);
        $this->assertSame(
            ['tc1' => ['name' => 'edit', 'displayLine' => 'edit path.php']],
            $wire['pending_tool_calls'],
        );
    }

    public function testMalformedPendingToolCallFailsAtBoundary(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();

        try {
            $codec->denormalize([
                'child_status' => 'running',
                'child_turn_no' => 1,
                'last_committed_seq' => 1,
                'model' => 'deepseek/deepseek-v4-flash',
                'reasoning' => 'medium',
                'pending_tool_calls' => [
                    'tc1' => ['name' => '', 'displayLine' => ''],
                ],
            ]);
            $this->fail('Expected domain validation failure for blank pending tool call fields.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Invalid deferred child lifecycle projection', $exception->getMessage());
            $this->assertInstanceOf(ValidationFailedException::class, $exception->getPrevious());
        }
    }

    public function testNonArrayPendingToolCallsFailsAtBoundary(): void
    {
        $codec = DeferredChildRunLifecycleProjectionCodecTestFactory::create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid deferred child lifecycle projection: pending_tool_calls must be an array.');
        $codec->denormalize([
            'child_status' => 'running',
            'child_turn_no' => 1,
            'last_committed_seq' => 1,
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
            'pending_tool_calls' => 'not-an-array',
        ]);
    }
}
