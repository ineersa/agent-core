<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Scheduling retains typed ToolBatchStateDTO graphs:
 * nested ExecuteToolCall/ToolCallResult objects via AttributeSerializer.
 * Configured Messenger + launch-context proof lives in
 * ToolLaunchContextConfiguredBoundaryTest.
 */
final class ToolBatchStateDTOParentModelRoundTripTest extends TestCase
{
    public function testCanonicalSnapshotRoundTripsTypedNestedObjectsAndPersistsBusIdentity(): void
    {
        [$serializer, $validator] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);
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
            batchToolCallCount: 2,
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

        $suspension = new ToolCallResult(
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-a',
            attempt: 1,
            idempotencyKey: 'suspension-ik',
            toolCallId: 'c1',
            orderIndex: 0,
            result: [],
            isError: false,
            error: null,
            pendingHumanInput: new PendingHumanInputRequestDTO(
                questionId: 'q-1',
                continuationKind: HumanInputContinuationKindEnum::ToolCall,
                payload: ['question_id' => 'q-1', 'prompt' => 'Approve command?'],
                continuationRef: ['run_id' => 'run-1', 'turn_no' => 2, 'step_id' => 'step-a', 'tool_call_id' => 'c1'],
            ),
        );

        $batch = new ToolBatchStateDTO(
            expectedOrder: ['c1' => 0, 'c2' => 1],
            calls: ['c1' => $call],
            pendingQueue: ['c1'],
            inFlight: ['c2' => true],
            results: ['c2' => $result, 'c1' => $suspension],
            finalized: false,
            maxParallelism: 2,
            awaitingHumanInput: ['c1' => 'q-1'],
        );

        $json = $serializer->serialize($batch, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]);
        $wire = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('call_data', $wire);
        $this->assertArrayHasKey('result_data', $wire);
        $this->assertSame('run-1', $wire['call_data']['c1']['run_id']);
        $this->assertSame(2, $wire['call_data']['c1']['turn_no']);
        $this->assertSame('step-a', $wire['call_data']['c1']['step_id']);
        $this->assertSame(3, $wire['call_data']['c1']['attempt']);
        $this->assertSame('live-ik', $wire['call_data']['c1']['idempotency_key']);
        $this->assertArrayHasKey('pending_human_input', $wire['result_data']['c2']);
        $this->assertNull($wire['result_data']['c2']['pending_human_input']);
        $this->assertSame('q-1', $wire['result_data']['c1']['pending_human_input']['question_id']);
        $this->assertSame('deepseek/deepseek-v4-flash', $wire['call_data']['c1']['parent_model']);
        $this->assertTrue(
            !\array_key_exists('launch_context', $wire['call_data']['c1'])
            || null === $wire['call_data']['c1']['launch_context'],
            'Ordinary tools must not carry a launch-context graph',
        );
        $this->assertSame('q-1', $wire['call_data']['c1']['human_input_answer']['question_id']);

        $restored = $serializer->deserialize(
            $json,
            ToolBatchStateDTO::class,
            'json',
            [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]],
        );
        $this->assertInstanceOf(ToolBatchStateDTO::class, $restored);
        $this->assertSame(0, $validator->validate($restored)->count());

        $this->assertSame('bash', $restored->calls['c1']->toolName);
        $this->assertSame(['command' => 'ls'], $restored->calls['c1']->args);
        $this->assertSame('deepseek/deepseek-v4-flash', $restored->calls['c1']->parentModel);
        $this->assertNotNull($restored->calls['c1']->humanInputAnswer);
        $this->assertSame('q-1', $restored->calls['c1']->humanInputAnswer->questionId);
        $this->assertNull($restored->calls['c1']->launchContext);
        $this->assertSame(['stdout' => 'ok'], $restored->results['c2']->result);
        $this->assertNull($restored->results['c2']->pendingHumanInput);
        $restoredSuspension = $restored->results['c1'];
        $this->assertTrue($restoredSuspension->isHumanInputSuspension());
        $this->assertNotNull($restoredSuspension->pendingHumanInput);
        $this->assertSame('suspension-ik', $restoredSuspension->idempotencyKey());
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $restoredSuspension->pendingHumanInput->continuationKind);
        $this->assertSame(['question_id' => 'q-1', 'prompt' => 'Approve command?'], $restoredSuspension->pendingHumanInput->payload);
        $this->assertSame(['run_id' => 'run-1', 'turn_no' => 2, 'step_id' => 'step-a', 'tool_call_id' => 'c1'], $restoredSuspension->pendingHumanInput->continuationRef);
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
            ], \JSON_THROW_ON_ERROR),
            ToolBatchStateDTO::class,
            'json',
            [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]],
        );
    }

    public function testBlankHumanInputAnswerQuestionIdFailsValidation(): void
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

        $violations = $validator->validate($batch);
        $this->assertGreaterThan(0, $violations->count());
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        $this->assertContains('calls[c1].humanInputAnswer.questionId', $paths);

        $json = $serializer->serialize($batch, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]);
        $restored = $serializer->deserialize(
            $json,
            ToolBatchStateDTO::class,
            'json',
            [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]],
        );
        $this->assertInstanceOf(ToolBatchStateDTO::class, $restored);
        $restoredViolations = $validator->validate($restored);
        $this->assertGreaterThan(0, $restoredViolations->count());
    }
}
