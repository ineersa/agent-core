<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Tool\RetainedNativeHandoffBodyPromoterInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedOutputCapBodyPromoterInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
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
        // Source-event seq is bound from the durable allocator in JsonlRunEventLog::withSeq.
        $this->assertArrayNotHasKey('accepted_source_event_reference', $accepted['retained_message']['origin']);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::Inline->value, $accepted['retained_message']['body']['kind']);
        $this->assertArrayNotHasKey('path', $accepted['retained_message']['body']);
    }

    public function testDetachedAcceptancePromotesNestedOutputCapAndNativeFromSerializedMessage(): void
    {
        $store = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector($store, 4, $deferred);
        $call = new ExecuteToolCall(
            runId: 'run-nested',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-nested-key',
            toolCallId: 'call-nested',
            toolName: 'bash',
            args: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
        );
        TestToolBatchRegistration::register($collector, $store, 'run-nested', 1, 'tools-old', [$call]);

        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440888',
            runId: 'run-nested',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-nested-key',
            toolCallId: 'call-nested',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: 'inv-nested',
            controllerRunId: 'run-nested',
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-nested', 9, '/tmp/nested.log'),
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
        $outputCapPromoter = new class($bodies) implements RetainedOutputCapBodyPromoterInterface {
            public ?string $seenSavedPath = null;

            public function __construct(
                private FakeRetainedToolCompletionBodyStore $bodies,
            ) {
            }

            public function promoteIfOwned(
                string $owningRootSessionId,
                string $messageId,
                array $normalizedToolResult,
                string $sourceRunId,
            ): ?RetainedToolCompletionBodyReference {
                $details = $normalizedToolResult['details'] ?? null;
                if (!\is_array($details) || !\is_array($details['output_cap'] ?? null)) {
                    return null;
                }
                $saved = $details['output_cap']['saved_path'] ?? null;
                if (!\is_string($saved)) {
                    return null;
                }
                $this->seenSavedPath = $saved;
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, 'FULL-CAPPED-BODY');
                rewind($stream);

                return $this->bodies->sealJsonStream($owningRootSessionId, $messageId, $stream, 'text/plain');
            }
        };

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            retainedCompletionFactory: RetainedCompletionTestFactory::envelopeFactory(
                bodies: $bodies,
                promoter: $outputCapPromoter,
            ),
            retainedCompletionCodec: RetainedCompletionTestFactory::codec(),
        );

        $message = ToolCallResultBuilder::success('run-nested')
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-nested-key')
            ->withToolCallId('call-nested')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'capped preview']],
                'details' => [
                    'output_cap' => [
                        'capped' => true,
                        'saved_path' => '/tmp/owned-full-output.txt',
                    ],
                ],
            ])
            ->build();

        $state = RunStateBuilder::running('run-nested')
            ->withTurnNo(2)
            ->withActiveStepId('tools-new')
            ->withLastSeq(10)
            ->withPendingToolCalls([])
            ->build();

        $result = $handler->handle($message, $state);
        $accepted = $result->events[0]->payload['accepted_completion'];
        $this->assertSame('/tmp/owned-full-output.txt', $outputCapPromoter->seenSavedPath);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed->value, $accepted['retained_message']['body']['kind']);
        $this->assertTrue($bodies->isHeld('run-nested', $accepted['completion_id']));

        // Native path selection from the same nested MESSAGE shape.
        $nativeBodies = new FakeRetainedToolCompletionBodyStore();
        $nativeSeen = null;
        $nativePromoter = new class($nativeBodies) implements RetainedNativeHandoffBodyPromoterInterface {
            public ?RetainedNativeHandoffGeneration $seen = null;

            public function __construct(private FakeRetainedToolCompletionBodyStore $bodies)
            {
            }

            public function sealOwnedCopy(
                string $owningRootSessionId,
                string $messageId,
                RetainedNativeHandoffGeneration $generation,
            ): RetainedToolCompletionBodyReference {
                $this->seen = $generation;
                $stream = fopen('php://temp', 'r+b');
                fwrite($stream, 'NATIVE');
                rewind($stream);

                return $this->bodies->sealJsonStream(
                    $owningRootSessionId,
                    $messageId,
                    $stream,
                    $generation->mediaType,
                    expectedBytes: 6,
                    expectedSha256: hash('sha256', 'NATIVE'),
                );
            }
        };

        $call2 = new ExecuteToolCall(
            runId: 'run-native-nested',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-native-nested-key',
            toolCallId: 'call-native-nested',
            toolName: 'tasks',
            args: ['tasks' => [['task' => 'x']]],
            orderIndex: 0,
            mode: 'sequential',
        );
        $store2 = new TestToolBatchStore();
        $deferred2 = new InMemoryDeferredToolCompletionRepository();
        $collector2 = new ToolBatchCollector($store2, 4, $deferred2);
        TestToolBatchRegistration::register($collector2, $store2, 'run-native-nested', 1, 'tools-old', [$call2]);
        $base2 = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440999',
            runId: 'run-native-nested',
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: 'call-native-nested-key',
            toolCallId: 'call-native-nested',
            toolName: 'tasks',
            arguments: ['tasks' => [['task' => 'x']]],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: 'inv-native-nested',
            controllerRunId: 'run-native-nested',
            executionOwnerKind: 'subagent',
            executionOwnerReference: new ToolInvocationOwnerReference('subagent', 'record-native', null, null),
            originalExecutionMode: 'sequential',
        );
        $correlation2 = $deferred2->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base2->deferredId,
            runId: $base2->runId,
            turnNo: $base2->turnNo,
            stepId: $base2->stepId,
            attempt: $base2->attempt,
            idempotencyKey: $base2->idempotencyKey,
            toolCallId: $base2->toolCallId,
            toolName: $base2->toolName,
            arguments: $base2->arguments,
            orderIndex: $base2->orderIndex,
            mode: $base2->mode,
            invocationId: $base2->invocationId,
            controllerRunId: $base2->controllerRunId,
            executionOwnerKind: $base2->executionOwnerKind,
            executionOwnerReference: $base2->executionOwnerReference,
            originalExecutionMode: $base2->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base2),
        ));
        $deferred2->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation2,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));

        $handler2 = new ToolCallResultHandler(
            toolBatchCollector: $collector2,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            serializer: AttributeSerializerValidatorTestFactory::denormalizer(),
            retainedCompletionFactory: RetainedCompletionTestFactory::envelopeFactory(
                bodies: $nativeBodies,
                nativeHandoffPromoter: $nativePromoter,
            ),
            retainedCompletionCodec: RetainedCompletionTestFactory::codec(),
        );
        $nativeBody = 'NATIVE';
        $message2 = ToolCallResultBuilder::success('run-native-nested')
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-native-nested-key')
            ->withToolCallId('call-native-nested')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'tasks',
                'content' => [['type' => 'text', 'text' => 'done']],
                'details' => [
                    'native_artifact_handoffs' => [[
                        'parent_run_id' => 'child-uuid',
                        'artifact_id' => 'agent_abc',
                        'handoff_id' => '0199aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee',
                        'bytes' => \strlen($nativeBody),
                        'sha256' => hash('sha256', $nativeBody),
                        'media_type' => 'text/markdown',
                    ]],
                ],
            ])
            ->build();
        $result2 = $handler2->handle(
            $message2,
            RunStateBuilder::running('run-native-nested')->withTurnNo(2)->withActiveStepId('tools-new')->withLastSeq(3)->withPendingToolCalls([])->build(),
        );
        $this->assertNotNull($nativePromoter->seen);
        $this->assertSame('child-uuid', $nativePromoter->seen->parentRunId);
        $accepted2 = $result2->events[0]->payload['accepted_completion'];
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed->value, $accepted2['retained_message']['body']['kind']);
    }
}
