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

/**
 * Session snapshot persistence serializes the real ToolBatchStateDTO graph:
 * nested ExecuteToolCall/ToolCallResult objects, bus envelope fields excluded.
 */
final class ToolBatchStateDTOParentModelRoundTripTest extends TestCase
{
    public function testCanonicalSnapshotRoundTripsTypedNestedObjectsAndExcludesBusEnvelope(): void
    {
        [$normalizer, $validator] = AttributeSerializerValidatorTestFactory::create();
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
            idempotencyKey: 'live-ik-must-not-persist',
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

        $envelope = ToolBatchSnapshotEnvelopeDTO::create('run-1', 2, 'step-a', $batch);
        $wire = $envelope->toArray($normalizer);
        $batchWire = $wire['batch_state'];

        $this->assertSame(['run_id', 'turn_no', 'step_id', 'batch_state'], array_keys($wire));
        $this->assertArrayHasKey('call_data', $batchWire);
        $this->assertArrayHasKey('result_data', $batchWire);
        $this->assertArrayNotHasKey('runId', $batchWire['call_data']['c1']);
        $this->assertArrayNotHasKey('turnNo', $batchWire['call_data']['c1']);
        $this->assertArrayNotHasKey('stepId', $batchWire['call_data']['c1']);
        $this->assertArrayNotHasKey('attempt', $batchWire['call_data']['c1']);
        $this->assertArrayNotHasKey('idempotencyKey', $batchWire['call_data']['c1']);
        $this->assertArrayNotHasKey('pendingHumanInput', $batchWire['result_data']['c2']);
        $this->assertSame('deepseek/deepseek-v4-flash', $batchWire['call_data']['c1']['parentModel']);
        $this->assertSame('q-1', $batchWire['call_data']['c1']['humanInputAnswer']['question_id']);

        $restored = ToolBatchSnapshotEnvelopeDTO::fromArray(
            $wire,
            'run-1',
            2,
            'step-a',
            '/tmp/tool-batch-roundtrip.json',
            $normalizer,
            $validator,
        )->batchState;

        $this->assertSame('bash', $restored->calls['c1']->toolName);
        $this->assertSame(['command' => 'ls'], $restored->calls['c1']->args);
        $this->assertSame('deepseek/deepseek-v4-flash', $restored->calls['c1']->parentModel);
        $this->assertNotNull($restored->calls['c1']->humanInputAnswer);
        $this->assertSame('q-1', $restored->calls['c1']->humanInputAnswer->questionId);
        $this->assertSame(['stdout' => 'ok'], $restored->results['c2']->result);
        $this->assertNull($restored->results['c2']->pendingHumanInput);
        // Bus identity reconstructed from envelope, not live message values.
        $this->assertSame('run-1', $restored->calls['c1']->runId());
        $this->assertSame(2, $restored->calls['c1']->turnNo());
        $this->assertSame('step-a', $restored->calls['c1']->stepId());
        $this->assertSame(1, $restored->calls['c1']->attempt());
        $this->assertSame(hash('sha256', 'run-1|step-a|c1'), $restored->calls['c1']->idempotencyKey());
        $this->assertSame(
            $normalizer->normalize($restored, null, [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]),
            $normalizer->normalize($batch, null, [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]),
        );
    }

    public function testMalformedBatchStateFailsStrictly(): void
    {
        [$normalizer, $validator] = AttributeSerializerValidatorTestFactory::create();

        try {
            ToolBatchSnapshotEnvelopeDTO::fromArray(
                [
                    'run_id' => 'run-x',
                    'turn_no' => 1,
                    'step_id' => 'step-x',
                    'batch_state' => [
                        'expected_order' => ['c1' => 0],
                        'call_data' => [
                            'c1' => [
                                'toolCallId' => 'c1',
                                'toolName' => 'read',
                                'orderIndex' => 0,
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
                ],
                'run-x',
                1,
                'step-x',
                '/tmp/tool-batch-malformed.json',
                $normalizer,
                $validator,
            );
            $this->fail('Expected SessionToolBatchStoreException for malformed call mode.');
        } catch (\Ineersa\CodingAgent\Session\SessionToolBatchStoreException $exception) {
            $this->assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
        }
    }
}
