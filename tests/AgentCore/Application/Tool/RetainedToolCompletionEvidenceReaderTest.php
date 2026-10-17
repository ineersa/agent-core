<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEvidenceReader;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedNativeArtifactHandoffReader;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\RetainedCompletionTestFactory;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RetainedToolCompletionEvidenceReaderTest extends IsolatedKernelTestCase
{
    #[DataProvider('scalarResults')]
    public function testReaderPreservesSealedJsonValuesAndBudgetOmission(string|int|float|bool|null $value): void
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('retained-json-value');
        $message = new ToolCallResult($runId, 1, 'step-1', 1, 'key-1', 'call-1', 0, $value);
        $payload = $container->get(ToolExecutionEndPayloadCodec::class)->toEventPayload($message);
        $envelope = $container->get(RetainedToolCompletionEnvelopeFactory::class)->build(
            completionId: 'completion-json-value',
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440904',
                runId: $runId,
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                toolCallId: 'call-1',
                toolName: 'bash',
                arguments: ['command' => 'true'],
                orderIndex: 0,
                executionOwnerKind: 'bash',
            ),
            result: $message,
            normalizedToolResult: $payload['tool_result'],
            executionOwnerKind: 'bash',
        );
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
        $payload['accepted_completion'] = [
            'completion_id' => $envelope->messageId,
            'retained_message' => $container->get(RetainedToolCompletionEnvelopeCodec::class)->normalize($envelope),
        ];
        $persisted = PreparedEventStoreSeeder::append(
            $container->get(PreparedTransitionEventStoreInterface::class),
            new RunEvent($runId, 0, 1, RunEventTypeEnum::ToolExecutionEnd->value, $payload),
        );
        $reader = new RetainedToolCompletionEvidenceReader(
            $container->get(ChildAwareEventStore::class),
            $container->get(RetainedToolCompletionEnvelopeCodec::class),
            $container->get(SessionRetainedToolCompletionBodyStore::class),
            new FakeRetainedNativeArtifactHandoffReader(),
        );
        $resolved = $reader->read($runId, $runId, $envelope->messageId, acceptedSourceSeq: $persisted->seq);
        $this->assertFalse($resolved['body']['truncated']);
        $this->assertArrayHasKey('json', $resolved['body']);
        $this->assertSame($value, $resolved['body']['json']);

        $withheld = $reader->read($runId, $runId, $envelope->messageId, maxBodyBytes: 0, acceptedSourceSeq: $persisted->seq);
        $this->assertTrue($withheld['body']['truncated']);
        $this->assertArrayNotHasKey('json', $withheld['body']);
    }

    /** @return iterable<string, array{string|int|float|bool|null}> */
    public static function scalarResults(): iterable
    {
        yield 'string' => ['plain-text'];
        yield 'zero' => [0];
        yield 'float' => [1.25];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'null' => [null];
    }

    public function testReaderResolvesInlineAndOwnedBodiesAndRejectsForeignCorruptUnsupported(): void
    {
        $events = new InMemoryEventStore();
        $bodies = new FakeRetainedToolCompletionBodyStore();
        $native = new FakeRetainedNativeArtifactHandoffReader();
        $codec = RetainedCompletionTestFactory::codec();
        $factory = RetainedCompletionTestFactory::envelopeFactory($bodies);
        $reader = new RetainedToolCompletionEvidenceReader($events, $codec, $bodies, $native);

        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440901',
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-1',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 0,
            invocationId: 'inv-1',
            controllerRunId: 'run-1',
            executionOwnerKind: 'bash',
        );
        $outcome = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'small']],
            'is_error' => false,
        ];
        [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $outcome,
        );
        $envelope = $factory->build(
            completionId: 'completion-small',
            correlation: $correlation,
            result: $result,
            normalizedToolResult: $normalized,
            executionOwnerKind: 'bash',
            controllerRunId: 'run-1',
        )->withAcceptedSourceEventReference(new RetainedToolCompletionSourceEventReference('run-1', 3, 1));

        $events->seed(new RunEvent('run-1', 1, 1, RunEventTypeEnum::RunStarted->value, []));
        $events->seed(new RunEvent('run-1', 2, 1, RunEventTypeEnum::ToolExecutionStart->value, []));
        $events->seed(new RunEvent('run-1', 3, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
            'tool_result' => $normalized,
            'accepted_completion' => [
                'completion_id' => 'completion-small',
                'result_fingerprint' => hash('sha256', json_encode($normalized, \JSON_THROW_ON_ERROR)),
                'origin_run_id' => 'run-1',
                'origin_turn_no' => 1,
                'origin_step_id' => 'step-1',
                'tool_call_id' => 'call-1',
                'invocation_id' => 'inv-1',
                'detached' => true,
                'evidence_only' => false,
                'retained_message' => $codec->normalize($envelope),
            ],
        ]));

        $resolved = $reader->read('run-1', 'run-1', 'completion-small', acceptedSourceSeq: 3);
        $this->assertSame('completion-small', $resolved['envelope']->messageId);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::Inline->value, $resolved['body']['kind']);
        $this->assertFalse($resolved['body']['truncated']);
        $this->assertSame('small', $resolved['body']['inline']['content'][0]['text']);

        try {
            $reader->read('foreign', 'run-1', 'completion-small', acceptedSourceSeq: 3);
            $this->fail('Expected foreign scope.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }

        try {
            $reader->read('run-1', 'run-1', 'missing', acceptedSourceSeq: 3);
            $this->fail('Expected not found.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::NotFound, $failure->code());
        }

        $corrupt = $codec->normalize($envelope);
        $corrupt['integrity']['payload_fingerprint'] = str_repeat('a', 64);
        $events->seed(new RunEvent('run-1', 4, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
            'accepted_completion' => [
                'completion_id' => 'completion-corrupt',
                'retained_message' => $corrupt,
            ],
        ]));
        try {
            $reader->read('run-1', 'run-1', 'completion-small', acceptedSourceSeq: 4);
            $this->fail('Expected corrupt fingerprint.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, $failure->code());
        }
    }

    public function testReaderResolvesOwnedSealedAndNativeBodies(): void
    {
        $events = new InMemoryEventStore();
        $bodies = new FakeRetainedToolCompletionBodyStore();
        $native = new FakeRetainedNativeArtifactHandoffReader();
        $codec = RetainedCompletionTestFactory::codec();
        $factory = RetainedCompletionTestFactory::envelopeFactory($bodies);
        $reader = new RetainedToolCompletionEvidenceReader($events, $codec, $bodies, $native);

        $large = str_repeat('L', 9000);
        $ownedOutcome = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => $large]],
            'is_error' => false,
        ];
        [$ownedResult, $ownedNormalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-owned', 'call-owned', 0, $ownedOutcome,
        );
        $ownedEnvelope = $factory->build(
            completionId: 'completion-owned',
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440902',
                runId: 'run-1',
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-owned',
                toolCallId: 'call-owned',
                toolName: 'bash',
                arguments: ['command' => 'true'],
                orderIndex: 0,
                invocationId: 'inv-owned',
                controllerRunId: 'run-1',
                executionOwnerKind: 'bash',
            ),
            result: $ownedResult,
            normalizedToolResult: $ownedNormalized,
            executionOwnerKind: 'bash',
            controllerRunId: 'run-1',
        )->withAcceptedSourceEventReference(new RetainedToolCompletionSourceEventReference('run-1', 5, 1));

        $nativeBody = 'native-body';
        $native->seed('run-1', 'agent_abc', '0199aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee', $nativeBody);
        $nativeOutcome = [
            'tool_name' => 'tasks',
            'content' => [['type' => 'text', 'text' => 'done']],
            'is_error' => false,
            'details' => [
                'native_artifact_handoffs' => [[
                    'parent_run_id' => 'run-1',
                    'artifact_id' => 'agent_abc',
                    'handoff_id' => '0199aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee',
                    'bytes' => \strlen($nativeBody),
                    'sha256' => hash('sha256', $nativeBody),
                    'media_type' => 'text/markdown',
                ]],
            ],
        ];
        [$nativeResult, $nativeNormalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-native', 'call-native', 1, $nativeOutcome,
        );
        $nativeEnvelope = $factory->build(
            completionId: 'completion-native',
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440903',
                runId: 'run-1',
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-native',
                toolCallId: 'call-native',
                toolName: 'tasks',
                arguments: [],
                orderIndex: 1,
                invocationId: 'inv-native',
                controllerRunId: 'run-1',
                executionOwnerKind: 'subagent',
            ),
            result: $nativeResult,
            normalizedToolResult: $nativeNormalized,
            executionOwnerKind: 'subagent',
            controllerRunId: 'run-1',
        )->withAcceptedSourceEventReference(new RetainedToolCompletionSourceEventReference('run-1', 6, 1));

        $events->seed(new RunEvent('run-1', 5, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
            'tool_result' => $ownedNormalized,
            'accepted_completion' => [
                'completion_id' => 'completion-owned',
                'result_fingerprint' => hash('sha256', json_encode($ownedNormalized, \JSON_THROW_ON_ERROR)),
                'origin_run_id' => 'run-1',
                'origin_turn_no' => 1,
                'origin_step_id' => 'step-1',
                'tool_call_id' => 'call-owned',
                'invocation_id' => 'inv-owned',
                'detached' => true,
                'evidence_only' => false,
                'retained_message' => $codec->normalize($ownedEnvelope),
            ],
        ]));
        $events->seed(new RunEvent('run-1', 6, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
            'tool_result' => $nativeNormalized,
            'accepted_completion' => [
                'completion_id' => 'completion-native',
                'result_fingerprint' => hash('sha256', json_encode($nativeNormalized, \JSON_THROW_ON_ERROR)),
                'origin_run_id' => 'run-1',
                'origin_turn_no' => 1,
                'origin_step_id' => 'step-1',
                'tool_call_id' => 'call-native',
                'invocation_id' => 'inv-native',
                'detached' => true,
                'evidence_only' => false,
                'retained_message' => $codec->normalize($nativeEnvelope),
            ],
        ]));

        $owned = $reader->read('run-1', 'run-1', 'completion-owned', acceptedSourceSeq: 5);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed->value, $owned['body']['kind']);
        $this->assertFalse($owned['body']['truncated']);
        $this->assertSame($large, $owned['body']['json']['content'][0]['text']);

        $nativeResolved = $reader->read('run-1', 'run-1', 'completion-native', acceptedSourceSeq: 6);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff->value, $nativeResolved['body']['kind']);
        $this->assertSame($nativeBody, $nativeResolved['body']['text']);
    }
}
