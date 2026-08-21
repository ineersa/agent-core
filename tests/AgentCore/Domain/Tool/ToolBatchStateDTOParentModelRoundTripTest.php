<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotEnvelopeDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Session snapshot persistence serializes the real ToolBatchStateDTO graph:
 * nested ExecuteToolCall/ToolCallResult objects via container-like Serializer.
 */
final class ToolBatchStateDTOParentModelRoundTripTest extends TestCase
{
    public function testCanonicalSnapshotRoundTripsTypedNestedObjectsAndPersistsBusIdentity(): void
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();
        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'q-1',
            answer: ['approved' => true],
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 2,
                'step_id' => 'step-a',
                'tool_call_id' => 'c1',
            ],
            requestPayload: ['hook' => 'safe_guard'],
        );
        $call = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-a',
            attempt: 3,
            idempotencyKey: 'live-ik',
            toolCallId: 'c1',
            toolName: 'bash',
            args: ['command' => 'ls'],
            orderIndex: 0,
            toolIdempotencyKey: 'tool-ik',
            mode: 'read_only',
            timeoutSeconds: 30,
            maxParallelism: 2,
            assistantMessage: ['role' => 'assistant'],
            argSchema: ['type' => 'object'],
            toolsRef: 'tools-v1',
            humanInputAnswer: $answer,
            parentModel: 'deepseek/deepseek-v4-flash',
            backgroundPromptAllowed: false,
        );
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-a',
            attempt: 1,
            idempotencyKey: 'live-result-ik',
            toolCallId: 'c2',
            orderIndex: 1,
            result: ['stdout' => 'ok'],
            isError: false,
            error: null,
        );

        $batch = new ToolBatchStateDTO(
            expectedOrder: ['c1' => 0, 'c2' => 1],
            calls: ['c1' => $call],
            pendingQueue: ['c1'],
            inFlight: ['c2' => true],
            results: ['c2' => $result],
            finalized: false,
            maxParallelism: 2,
            awaitingHumanInput: ['c1' => 'q-1'],
        );

        $envelope = new ToolBatchSnapshotEnvelopeDTO('run-1', 2, 'step-a', $batch);
        $json = $serializer->serialize($envelope, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]);
        $wire = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $batchWire = $wire['batch_state'];

        $this->assertSame(['run_id', 'turn_no', 'step_id', 'batch_state'], array_keys($wire));
        $this->assertArrayHasKey('call_data', $batchWire);
        $this->assertArrayHasKey('result_data', $batchWire);
        $this->assertSame('run-1', $batchWire['call_data']['c1']['run_id']);
        $this->assertSame(2, $batchWire['call_data']['c1']['turn_no']);
        $this->assertSame('step-a', $batchWire['call_data']['c1']['step_id']);
        $this->assertSame(3, $batchWire['call_data']['c1']['attempt']);
        $this->assertSame('live-ik', $batchWire['call_data']['c1']['idempotency_key']);
        $this->assertArrayNotHasKey('pending_human_input', $batchWire['result_data']['c2']);
        $this->assertSame('deepseek/deepseek-v4-flash', $batchWire['call_data']['c1']['parent_model']);
        $this->assertFalse($batchWire['call_data']['c1']['background_prompt_allowed']);
        $this->assertSame('q-1', $batchWire['call_data']['c1']['human_input_answer']['question_id']);

        $restoredEnvelope = $serializer->deserialize(
            $json,
            ToolBatchSnapshotEnvelopeDTO::class,
            'json',
            [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]],
        );
        $this->assertInstanceOf(ToolBatchSnapshotEnvelopeDTO::class, $restoredEnvelope);
        $this->assertSame(0, $validator->validate($restoredEnvelope)->count());
        $restored = $restoredEnvelope->batchState;

        $this->assertSame('bash', $restored->calls['c1']->toolName);
        $this->assertSame(['command' => 'ls'], $restored->calls['c1']->args);
        $this->assertSame('deepseek/deepseek-v4-flash', $restored->calls['c1']->parentModel);
        $this->assertFalse($restored->calls['c1']->backgroundPromptAllowed);
        $this->assertNotNull($restored->calls['c1']->humanInputAnswer);
        $this->assertSame('q-1', $restored->calls['c1']->humanInputAnswer->questionId);
        $this->assertSame(['stdout' => 'ok'], $restored->results['c2']->result);
        $this->assertNull($restored->results['c2']->pendingHumanInput);
        $this->assertSame('run-1', $restored->calls['c1']->runId());
        $this->assertSame(2, $restored->calls['c1']->turnNo());
        $this->assertSame('step-a', $restored->calls['c1']->stepId());
        $this->assertSame(3, $restored->calls['c1']->attempt());
        $this->assertSame('live-ik', $restored->calls['c1']->idempotencyKey());
        $this->assertSame(
            $serializer->normalize($restored, null, [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]),
            $serializer->normalize($batch, null, [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]),
        );
        $this->assertInstanceOf(SerializerInterface::class, $serializer);
    }

    public function testMalformedBatchStateFailsStrictly(): void
    {
        [$serializer] = AttributeSerializerValidatorTestFactory::create();

        $this->expectException(\Throwable::class);
        $serializer->deserialize(
            json_encode([
                'run_id' => 'run-x',
                'turn_no' => 1,
                'step_id' => 'step-x',
                'batch_state' => [
                    'expected_order' => ['c1' => 0],
                    'call_data' => [
                        'c1' => [
                            'tool_call_id' => 'c1',
                            'tool_name' => 'read',
                            'order_index' => 0,
                            'args' => [],
                            'mode' => 99,
                        ],
                    ],
                    'pending_queue' => [],
                    'in_flight' => [],
                    'result_data' => [],
                    'finalized' => false,
                    'max_parallelism' => 1,
                    'awaiting_human_input' => [],
                ],
            ], \JSON_THROW_ON_ERROR),
            ToolBatchSnapshotEnvelopeDTO::class,
            'json',
            [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]],
        );
    }

    public function testBlankHumanInputAnswerQuestionIdCascadesFromEnvelope(): void
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create();
        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: '',
            answer: ['approved' => true],
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 2,
                'step_id' => 'step-a',
                'tool_call_id' => 'c1',
            ],
            requestPayload: ['hook' => 'safe_guard'],
        );
        $call = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-a',
            attempt: 1,
            idempotencyKey: 'ik',
            toolCallId: 'c1',
            toolName: 'bash',
            args: [],
            orderIndex: 0,
            humanInputAnswer: $answer,
        );
        $batch = new ToolBatchStateDTO(
            expectedOrder: ['c1' => 0],
            calls: ['c1' => $call],
            pendingQueue: [],
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: 1,
        );
        $envelope = new ToolBatchSnapshotEnvelopeDTO('run-1', 2, 'step-a', $batch);

        $violations = $validator->validate($envelope);
        $this->assertGreaterThan(0, $violations->count());
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        $this->assertContains('batchState.calls[c1].humanInputAnswer.questionId', $paths);

        // Serializer path also hydrates blank nested answer; validate after deserialize.
        $json = $serializer->serialize($envelope, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]);
        $restored = $serializer->deserialize(
            $json,
            ToolBatchSnapshotEnvelopeDTO::class,
            'json',
            [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]],
        );
        $this->assertInstanceOf(ToolBatchSnapshotEnvelopeDTO::class, $restored);
        $restoredViolations = $validator->validate($restored);
        $this->assertGreaterThan(0, $restoredViolations->count());
    }
}
