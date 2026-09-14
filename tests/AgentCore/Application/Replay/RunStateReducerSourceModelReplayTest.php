<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Replay;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;

final class RunStateReducerSourceModelReplayTest extends TestCase
{
    public function testReplaysAssistantSourceModelFromEventMetadata(): void
    {
        $reducer = new RunStateReducer(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::denormalizer()),
        );

        $state = $reducer->replay(new RunState(
            runId: 'run-source-model-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
        ), [
            new RunEvent(
                runId: 'run-source-model-1',
                seq: 1,
                turnNo: 1,
                type: 'llm_step_completed',
                payload: [
                    'step_id' => 'step-1',
                    'model' => 'zai/glm-5.3-flash',
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'Using bash']],
                        'tool_calls' => [[
                            'id' => 'call_845adac454c64712b769d15b',
                            'name' => 'bash',
                            'arguments' => ['command' => 'pwd'],
                            'order_index' => 0,
                        ]],
                        'metadata' => ['source_model' => 'zai/glm-5.3-flash'],
                    ],
                ],
            ),
        ]);

        $this->assertCount(1, $state->messages);
        $this->assertSame('zai/glm-5.3-flash', $state->messages[0]->metadata['source_model'] ?? null);
        $this->assertSame('call_845adac454c64712b769d15b', $state->messages[0]->metadata['tool_calls'][0]['id'] ?? null);
    }

    public function testFallsBackToStepModelWhenAssistantMetadataMissing(): void
    {
        $reducer = new RunStateReducer(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::denormalizer()),
        );

        $state = $reducer->replay(new RunState(
            runId: 'run-source-model-2',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
        ), [
            new RunEvent(
                runId: 'run-source-model-2',
                seq: 1,
                turnNo: 1,
                type: 'llm_step_completed',
                payload: [
                    'step_id' => 'step-1',
                    'model' => 'openai-codex/gpt-6-astra',
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'hello']],
                    ],
                ],
            ),
        ]);

        $this->assertSame('openai-codex/gpt-6-astra', $state->messages[0]->metadata['source_model'] ?? null);
    }
}
