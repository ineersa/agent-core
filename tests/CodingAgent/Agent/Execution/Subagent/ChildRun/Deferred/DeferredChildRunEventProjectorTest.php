<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressParallelChildReportDTO;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: committed child projection must expose only safe tool argument pairs,
 * never raw secrets or full arg blobs, while preserving assistant result text and
 * honoring parent-committed status/turn overrides. Completed and failed LLM steps
 * each increment one durable llm_step_count that round-trips through DTO serialization.
 * Retryable llm_step_failed stays nonterminal so deferred child/fork completion cannot
 * hand off failure before the bounded auto-retry runs.
 * Canonical run_started.metadata.model always overrides a stale definition/current model
 * and must survive later incremental apply without run_started (resume/failed-child).
 */
final class DeferredChildRunEventProjectorTest extends TestCase
{
    public function testCanonicalRunStartedModelOverridesStaleDefinitionAndSurvivesResume(): void
    {
        $projector = new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $current = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: 0,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );

        $failed = $projector->apply(
            $current,
            [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::RunStarted->value, [
                    'payload' => ['metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent-1',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_1',
                        ],
                        'model' => 'openai-codex/gpt-5.6-sol',
                        'reasoning' => 'xhigh',
                        'tools_scope' => ['allowed_tools' => []],
                    ]],
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepFailed->value, [
                    'error' => [
                        'message' => 'provider overloaded',
                        'user_message' => 'LLM provider network error.',
                        'retryable' => false,
                    ],
                    'retryable' => false,
                ]),
            ],
            committedStatus: RunStatus::Failed,
            committedTurnNo: 3,
        );

        $this->assertSame(RunStatus::Failed, $failed->childStatus);
        $this->assertSame('openai-codex/gpt-5.6-sol', $failed->model);
        $this->assertSame('xhigh', $failed->reasoning);
        $this->assertSame(1, $failed->llmStepCount);

        // Recovery/resume apply without another run_started must keep launch model/reasoning.
        $resumed = $projector->apply(
            $failed,
            [
                new AfterTurnCommitEventSummary(3, RunEventTypeEnum::LlmStepCompleted->value, [
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 1, 'total_tokens' => 6],
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'partial recovery']],
                    ],
                ]),
            ],
            committedStatus: RunStatus::Running,
            committedTurnNo: 4,
        );

        $this->assertSame('openai-codex/gpt-5.6-sol', $resumed->model);
        $this->assertSame('xhigh', $resumed->reasoning);
        $this->assertSame(2, $resumed->llmStepCount);
        $this->assertSame(4, $resumed->childTurnNo);
        [$serializer] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);
        $wire = $serializer->normalize($resumed, null, [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
        $this->assertSame('xhigh', $serializer->denormalize($wire, DeferredChildRunLifecycleProjectionDTO::class)->reasoning);
    }

    public function testRetryableLlmStepFailedStaysRunningWhileExhaustedFailureIsTerminal(): void
    {
        $projector = new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $current = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: 0,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );

        // Session-shaped: LlmStepResultHandler commits retryable failure as
        // llm_step_failed(retryable=true) then trailing ModelNotification specs in the
        // same tail, with committedStatus Failed. Pending-retry must survive the
        // ignored notification (literal last-summary checks would wrongly terminalize).
        $retryPending = $projector->apply(
            $current,
            [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepFailed->value, [
                    'error' => [
                        'message' => 'Codex WebSocket request frame could not be sent.',
                        'user_message' => 'LLM provider network error (retryable). Will retry automatically.',
                        'retryable' => true,
                        'error_category' => 'network',
                    ],
                    'retryable' => true,
                    'step_id' => 'advance-after-tools-sync',
                    'retry_attempt' => 1,
                    'max_retries' => 2,
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::ModelNotification->value, [
                    'source' => 'transform_hook',
                    'message' => 'provider diagnostic notification',
                ]),
            ],
            committedStatus: RunStatus::Failed,
            committedTurnNo: 47,
        );

        $this->assertSame(RunStatus::Running, $retryPending->childStatus);
        $this->assertFalse($retryPending->childStatus->isTerminal());
        $this->assertSame(47, $retryPending->childTurnNo);
        $this->assertSame(1, $retryPending->llmStepCount);
        $this->assertSame(2, $retryPending->lastCommittedSeq);
        $this->assertSame(
            'LLM provider network error (retryable). Will retry automatically.',
            $retryPending->errorMessage,
        );

        // Later exhausted/non-retryable failure in a batched tail clears pending-retry
        // and remains terminal Failed (forgotten flag reset would leave Running).
        $exhausted = $projector->apply(
            $retryPending,
            [
                new AfterTurnCommitEventSummary(3, RunEventTypeEnum::ModelNotification->value, [
                    'source' => 'transform_hook',
                    'message' => 'ignored between attempts',
                ]),
                new AfterTurnCommitEventSummary(4, RunEventTypeEnum::LlmStepFailed->value, [
                    'error' => [
                        'message' => 'Codex WebSocket request frame could not be sent.',
                        'user_message' => 'Automatic LLM retry attempts exhausted after 2 retry attempt(s).',
                        'retryable' => false,
                        'error_category' => 'network',
                    ],
                    'retryable' => false,
                    'retries_exhausted' => true,
                    'step_id' => 'advance-after-tools-sync',
                    'retry_attempt' => 2,
                    'max_retries' => 2,
                ]),
            ],
            committedStatus: RunStatus::Failed,
            committedTurnNo: 48,
        );

        $this->assertSame(RunStatus::Failed, $exhausted->childStatus);
        $this->assertTrue($exhausted->childStatus->isTerminal());
        $this->assertSame(48, $exhausted->childTurnNo);
        $this->assertSame(2, $exhausted->llmStepCount);
        $this->assertSame(4, $exhausted->lastCommittedSeq);
        $this->assertSame(
            'Automatic LLM retry attempts exhausted after 2 retry attempt(s).',
            $exhausted->errorMessage,
        );
    }

    public function testApplyEnforcesPrivacyStatusOverridesAndMalformedArgumentSafety(): void
    {
        $projector = new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $current = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: 0,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );

        $longText = str_repeat('Z', 300);
        $secretArgs = json_encode(['path' => '/safe/path.php', 'api_key' => 'super-secret', 'new_string' => 'leak'], \JSON_THROW_ON_ERROR);
        $summaries = [
            new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $longText]]],
            ]),
            new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepCompleted->value, [
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => '']],
                    'tool_calls' => [['id' => 'tc1', 'name' => 'read', 'arguments' => $secretArgs]],
                ],
            ]),
            new AfterTurnCommitEventSummary(3, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_result' => [
                    'run_id' => 'child-run',
                    'turn_no' => 4,
                    'step_id' => 'step-1',
                    'attempt' => 1,
                    'idempotency_key' => 'result-tc1',
                    'tool_call_id' => 'tc1',
                    'order_index' => 0,
                    'result' => ['tool_name' => 'read', 'content' => []],
                    'is_error' => false,
                    'error' => null,
                    'pending_human_input' => null,
                ],
            ]),
        ];

        $projection = $projector->apply(
            $current,
            $summaries,
            committedStatus: RunStatus::WaitingHuman,
            committedTurnNo: 4,
        );

        $this->assertNotEmpty($projection->recentTools);
        $this->assertStringContainsString('safe/path.php', $projection->recentTools[0]);
        $json = json_encode($projection, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('super-secret', $json);
        $this->assertStringNotContainsString('leak', $json);
        $this->assertStringNotContainsString('"args"', $json);
        $this->assertSame(RunStatus::WaitingHuman, $projection->childStatus);
        $this->assertSame(4, $projection->childTurnNo);
        $this->assertSame(2, $projection->llmStepCount);
        $this->assertSame($longText, $projection->assistantResultText);
        $this->assertLessThanOrEqual(220, mb_strlen((string) $projection->assistantExcerpt));

        $projection = $projector->apply(
            $projection,
            [new AfterTurnCommitEventSummary(4, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 5])],
            committedStatus: RunStatus::Running,
            committedTurnNo: 5,
        );
        $this->assertSame(RunStatus::Running, $projection->childStatus);
        $this->assertSame(5, $projection->childTurnNo);
        $this->assertSame(2, $projection->llmStepCount);

        $malformed = new AfterTurnCommitEventSummary(5, RunEventTypeEnum::LlmStepCompleted->value, [
            'assistant_message' => [
                'role' => 'assistant',
                'tool_calls' => [['id' => 'tc2', 'name' => 'grep', 'arguments' => '{not-json']],
            ],
        ]);
        $projection = $projector->apply(
            $projection,
            [$malformed],
            committedStatus: RunStatus::Compacting,
            committedTurnNo: 5,
        );
        $this->assertSame(RunStatus::Compacting, $projection->childStatus);
        $this->assertSame(3, $projection->llmStepCount);
        $this->assertStringContainsString('grep', json_encode($projection, \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('not-json', json_encode($projection, \JSON_THROW_ON_ERROR));

        $projection = $projector->apply(
            $projection,
            [new AfterTurnCommitEventSummary(6, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 6])],
            committedStatus: RunStatus::Cancelling,
            committedTurnNo: 6,
        );
        $this->assertSame(RunStatus::Cancelling, $projection->childStatus);
        $this->assertSame(6, $projection->childTurnNo);
        $this->assertSame(3, $projection->llmStepCount);
    }

    public function testCompletedAndFailedLlmStepsIncrementDurableCounterAndRoundTrip(): void
    {
        $projector = new DeferredChildRunEventProjector(AttributeSerializerValidatorTestFactory::denormalizer(), new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
        $current = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: 0,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );

        $projection = $projector->apply(
            $current,
            [
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::LlmStepCompleted->value, [
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12],
                    'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]],
                ]),
                new AfterTurnCommitEventSummary(2, RunEventTypeEnum::LlmStepFailed->value, [
                    'error' => ['message' => 'provider overloaded'],
                ]),
                new AfterTurnCommitEventSummary(3, RunEventTypeEnum::LlmStepCompleted->value, [
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 3, 'total_tokens' => 23],
                ]),
            ],
            committedStatus: RunStatus::Running,
            committedTurnNo: 7,
        );

        $this->assertSame(3, $projection->llmStepCount);
        $this->assertSame(7, $projection->childTurnNo);
        $this->assertSame(30, $projection->inputTokens);

        [$serializer] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);
        $wire = $serializer->normalize($projection, null, [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
        $roundTrip = $serializer->denormalize($wire, DeferredChildRunLifecycleProjectionDTO::class);
        $this->assertSame(3, $roundTrip->llmStepCount);
        $this->assertSame(3, $wire['llm_step_count'] ?? null);
        $this->assertSame(7, $roundTrip->childTurnNo);

        $summary = new \Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary(
            model: $roundTrip->model,
            reasoning: $roundTrip->reasoning,
            toolCount: $roundTrip->toolCount,
            llmStepCount: $roundTrip->llmStepCount,
            inputTokens: $roundTrip->inputTokens,
            latestInputTokens: $roundTrip->latestInputTokens,
            contextWindow: $roundTrip->contextWindow ?? 0,
            outputTokens: $roundTrip->outputTokens,
            reasoningTokens: $roundTrip->reasoningTokens,
            totalTokens: $roundTrip->totalTokens,
            cost: $roundTrip->cost,
            artifactPath: 'artifacts/agents/agent_llm_steps',
            assistantExcerpt: $roundTrip->assistantExcerpt,
            recentTools: $roundTrip->recentTools,
            activeToolLine: $roundTrip->activeToolLine,
        );
        $this->assertSame(3, $summary->llmStepCount);

        $snapshot = (new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder())->singleFromChildTurn(
            agentName: 'scout',
            artifactId: 'agent_llm_steps',
            agentRunId: 'child-run-llm-steps',
            taskSummary: 'count steps',
            childTurnNo: $roundTrip->childTurnNo,
            elapsedMs: 1000,
            enrichment: $summary,
            status: 'completed',
        );
        $singlePayload = SubagentProgressSerializerTestSupport::normalizer()->normalize($snapshot);
        $this->assertSame(3, $singlePayload['llm_step_count'] ?? null);
        $this->assertSame(7, $singlePayload['turn_no'] ?? null);

        $parallel = (new \Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder())->parallelSnapshot(
            reports: [
                'child-run-llm-steps' => new SubagentProgressParallelChildReportDTO(
                    index: 1,
                    agentName: 'scout',
                    task: 'count steps',
                    artifactId: 'agent_llm_steps',
                    agentRunId: 'child-run-llm-steps',
                    terminal: true,
                    status: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum::Completed,
                ),
            ],
            activeTurns: ['child-run-llm-steps' => $roundTrip->childTurnNo],
            elapsedMs: 1000,
            enrichmentByAgentRunId: ['child-run-llm-steps' => $summary],
            aggregateStatus: 'completed',
        );
        $parallelPayload = SubagentProgressSerializerTestSupport::normalizer()->normalize($parallel);
        $this->assertSame(3, $parallelPayload['children'][0]['llm_step_count'] ?? null);
        $this->assertSame(7, $parallelPayload['children'][0]['turn_no'] ?? null);
    }
}
