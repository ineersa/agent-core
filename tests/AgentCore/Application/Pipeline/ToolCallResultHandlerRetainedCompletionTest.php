<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\RetainedCompletionTestFactory;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use PHPUnit\Framework\TestCase;

final class ToolCallResultHandlerRetainedCompletionTest extends TestCase
{
    public function testDetachedAcceptanceRetainsImmutableEnvelopeWithSourceEventReference(): void
    {
        $store = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector($store, 4, $deferred);
        $call = new ExecuteToolCall(
            runId: 'run-retained',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-retained-key',
            toolCallId: 'call-retained',
            toolName: 'bash',
            args: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-retained', 1, 'tools-old', [$call]);

        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440777',
            runId: 'run-retained',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-retained-key',
            toolCallId: 'call-retained',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: 'inv-retained',
            controllerRunId: 'run-retained',
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-retained', 9, '/tmp/retained.log'),
            originalExecutionMode: 'sequential',
        );
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));

        $bodies = new FakeRetainedToolCompletionBodyStore();
        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            retainedCompletionFactory: RetainedCompletionTestFactory::envelopeFactory($bodies),
            retainedCompletionCodec: RetainedCompletionTestFactory::codec(),
        );

        $message = ToolCallResultBuilder::success('run-retained')
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-retained-key')
            ->withToolCallId('call-retained')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $state = RunStateBuilder::running('run-retained')
            ->withTurnNo(2)
            ->withActiveStepId('tools-new')
            ->withLastSeq(10)
            ->withPendingToolCalls([])
            ->build();

        $result = $handler->handle($message, $state);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ToolExecutionEnd->value, $result->events[0]->type);
        $accepted = $result->events[0]->payload['accepted_completion'];
        $this->assertSame($accepted['completion_id'], $accepted['retained_message']['message_id']);
        $this->assertSame('tool_completion', $accepted['retained_message']['kind']);
        $this->assertSame('runtime', $accepted['retained_message']['provenance']['producer']);
        $this->assertSame(11, $accepted['retained_message']['origin']['accepted_source_event_reference']['seq']);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::Inline->value, $accepted['retained_message']['body']['kind']);
        $this->assertArrayNotHasKey('path', $accepted['retained_message']['body']);
    }
}
