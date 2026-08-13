<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Tests\Support\ToolBatchStateCodecTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: tool-batch snapshot batch_state fixed rows normalize/denormalize through
 * one Serializer codec with exact historical wire shape and recovery semantics.
 */
final class ToolBatchStateCodecTest extends TestCase
{
    public function testFullSnapshotRoundTripsTypedRowsAndWireShape(): void
    {
        $codec = ToolBatchStateCodecTestFactory::create();
        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'q-1',
            answer: ['approved' => true],
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 2,
                'step_id' => 'step-a',
                'tool_call_id' => 'c1',
            ],
            requestPayload: ['hook' => 'safe_guard', 'approval_context' => ['path' => 'x']],
        );
        $call = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-a',
            attempt: 1,
            idempotencyKey: 'ik-c1',
            toolCallId: 'c1',
            toolName: 'bash',
            args: ['command' => 'ls'],
            orderIndex: 0,
            toolIdempotencyKey: 'tool-ik',
            mode: 'read_only',
            timeoutSeconds: 30,
            maxParallelism: 2,
            assistantMessage: ['role' => 'assistant', 'content' => 'go'],
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
            idempotencyKey: 'ik-c2',
            toolCallId: 'c2',
            orderIndex: 1,
            result: ['stdout' => 'ok', 'details' => ['exit' => 0]],
            isError: false,
            error: null,
        );
        $errorResult = new ToolCallResult(
            runId: 'run-1',
            turnNo: 2,
            stepId: 'step-a',
            attempt: 1,
            idempotencyKey: 'ik-c3',
            toolCallId: 'c3',
            orderIndex: 2,
            result: null,
            isError: true,
            error: ['message' => 'boom', 'code' => 'E_TOOL'],
        );

        $batch = new ToolBatchStateDTO(
            expectedOrder: ['c1' => 0, 'c2' => 1, 'c3' => 2],
            calls: ['c1' => $call],
            pendingQueue: ['c1'],
            inFlight: ['c2' => true],
            results: ['c2' => $result, 'c3' => $errorResult],
            finalized: false,
            maxParallelism: 2,
            awaitingHumanInput: ['c1' => 'q-1'],
        );

        $wire = $codec->normalize($batch);
        // Exact historical toPersistedArray key order from pre-phase-6 ToolBatchStateDTO.
        $this->assertSame([
            'expected_order' => ['c1' => 0, 'c2' => 1, 'c3' => 2],
            'call_data' => [
                'c1' => [
                    'toolCallId' => 'c1',
                    'toolName' => 'bash',
                    'args' => ['command' => 'ls'],
                    'orderIndex' => 0,
                    'mode' => 'read_only',
                    'timeoutSeconds' => 30,
                    'maxParallelism' => 2,
                    'toolsRef' => 'tools-v1',
                    'toolIdempotencyKey' => 'tool-ik',
                    'assistantMessage' => ['role' => 'assistant', 'content' => 'go'],
                    'argSchema' => ['type' => 'object'],
                    'humanInputAnswer' => [
                        'question_id' => 'q-1',
                        'answer' => ['approved' => true],
                        'continuation_ref' => [
                            'run_id' => 'run-1',
                            'turn_no' => 2,
                            'step_id' => 'step-a',
                            'tool_call_id' => 'c1',
                        ],
                        'request_payload' => ['hook' => 'safe_guard', 'approval_context' => ['path' => 'x']],
                    ],
                    'parentModel' => 'deepseek/deepseek-v4-flash',
                ],
            ],
            'pending_queue' => ['c1'],
            'in_flight' => ['c2' => true],
            'result_data' => [
                'c2' => [
                    'toolCallId' => 'c2',
                    'orderIndex' => 1,
                    'result' => ['stdout' => 'ok', 'details' => ['exit' => 0]],
                    'isError' => false,
                    'error' => null,
                ],
                'c3' => [
                    'toolCallId' => 'c3',
                    'orderIndex' => 2,
                    'result' => null,
                    'isError' => true,
                    'error' => ['message' => 'boom', 'code' => 'E_TOOL'],
                ],
            ],
            'finalized' => false,
            'max_parallelism' => 2,
            'awaiting_human_input' => ['c1' => 'q-1'],
        ], $wire);
        $this->assertSame(
            [
                'toolCallId',
                'toolName',
                'args',
                'orderIndex',
                'mode',
                'timeoutSeconds',
                'maxParallelism',
                'toolsRef',
                'toolIdempotencyKey',
                'assistantMessage',
                'argSchema',
                'humanInputAnswer',
                'parentModel',
            ],
            array_keys($wire['call_data']['c1']),
        );
        $this->assertSame(
            ['toolCallId', 'orderIndex', 'result', 'isError', 'error'],
            array_keys($wire['result_data']['c2']),
        );
        $this->assertSame(
            ['question_id', 'answer', 'continuation_ref', 'request_payload'],
            array_keys($wire['call_data']['c1']['humanInputAnswer']),
        );
        $this->assertSame(
            [
                'expected_order',
                'call_data',
                'pending_queue',
                'in_flight',
                'result_data',
                'finalized',
                'max_parallelism',
                'awaiting_human_input',
            ],
            array_keys($wire),
        );

        $restored = $codec->denormalize($wire, 'run-1', 2, 'step-a');
        $this->assertSame($wire, $codec->normalize($restored));
        $this->assertSame('bash', $restored->calls['c1']->toolName);
        $this->assertSame(['command' => 'ls'], $restored->calls['c1']->args);
        $this->assertSame('deepseek/deepseek-v4-flash', $restored->calls['c1']->parentModel);
        $this->assertNotNull($restored->calls['c1']->humanInputAnswer);
        $this->assertSame('q-1', $restored->calls['c1']->humanInputAnswer->questionId);
        $this->assertSame(['approved' => true], $restored->calls['c1']->humanInputAnswer->answer);
        $this->assertSame(['stdout' => 'ok', 'details' => ['exit' => 0]], $restored->results['c2']->result);
        $this->assertTrue($restored->results['c3']->isError);
        $this->assertSame(['message' => 'boom', 'code' => 'E_TOOL'], $restored->results['c3']->error);
        $this->assertSame(['c2' => true], $restored->inFlight);
        $this->assertSame(['c1' => 'q-1'], $restored->awaitingHumanInput);
        // Bus envelope fields reconstructed, not persisted.
        $this->assertSame('run-1', $restored->calls['c1']->runId());
        $this->assertSame(2, $restored->calls['c1']->turnNo());
        $this->assertSame('step-a', $restored->calls['c1']->stepId());
        $this->assertSame(1, $restored->calls['c1']->attempt());
        $this->assertSame(hash('sha256', 'run-1|step-a|c1'), $restored->calls['c1']->idempotencyKey());
        $this->assertNull($restored->results['c2']->pendingHumanInput);
    }

    public function testMinimalHistoricalSnapshotHydrates(): void
    {
        $codec = ToolBatchStateCodecTestFactory::create();
        $historical = [
            'expected_order' => ['c1' => 0],
            'call_data' => [
                'c1' => [
                    'toolCallId' => 'c1',
                    'toolName' => 'read',
                    'orderIndex' => 0,
                ],
            ],
            'pending_queue' => ['c1'],
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 1,
            'awaiting_human_input' => [],
        ];

        $batch = $codec->denormalize($historical, 'run-h', 1, 'step-h');
        $this->assertSame(['c1' => 0], $batch->expectedOrder);
        $this->assertSame('read', $batch->calls['c1']->toolName);
        $this->assertSame([], $batch->calls['c1']->args);
        $this->assertNull($batch->calls['c1']->mode);
        $this->assertNull($batch->calls['c1']->parentModel);
        $this->assertNull($batch->calls['c1']->humanInputAnswer);
        $this->assertSame(['c1'], $batch->pendingQueue);
        $this->assertSame([], $batch->results);

        // Normalize rewrites missing optional keys with explicit null defaults.
        $rewritten = $codec->normalize($batch);
        $this->assertArrayHasKey('mode', $rewritten['call_data']['c1']);
        $this->assertNull($rewritten['call_data']['c1']['mode']);
        $this->assertSame([], $rewritten['call_data']['c1']['args']);
        $this->assertSame(
            [
                'toolCallId',
                'toolName',
                'args',
                'orderIndex',
                'mode',
                'timeoutSeconds',
                'maxParallelism',
                'toolsRef',
                'toolIdempotencyKey',
                'assistantMessage',
                'argSchema',
                'humanInputAnswer',
                'parentModel',
            ],
            array_keys($rewritten['call_data']['c1']),
        );
    }

    public function testMalformedRowsFailClosed(): void
    {
        $codec = ToolBatchStateCodecTestFactory::create();

        try {
            $codec->denormalize([
                'expected_order' => ['c1' => 'nope'],
                'call_data' => [],
                'pending_queue' => [],
                'in_flight' => [],
                'result_data' => [],
                'finalized' => false,
                'max_parallelism' => 1,
                'awaiting_human_input' => [],
            ], 'run-x', 1, 'step-x');
            $this->fail('Expected UnexpectedValueException for bad expected_order.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('expected_order', $exception->getMessage());
        }

        try {
            $codec->denormalize([
                'expected_order' => ['c1' => 0],
                'call_data' => [
                    'c1' => [
                        'toolCallId' => 'c1',
                        'toolName' => 'read',
                        'orderIndex' => 0,
                        'mode' => 99,
                    ],
                ],
                'pending_queue' => [],
                'in_flight' => [],
                'result_data' => [],
                'finalized' => false,
                'max_parallelism' => 1,
                'awaiting_human_input' => [],
            ], 'run-x', 1, 'step-x');
            $this->fail('Expected UnexpectedValueException for bad mode type.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('mode', $exception->getMessage());
        }

        try {
            $codec->denormalize([
                'expected_order' => [],
                'call_data' => [],
                'pending_queue' => [],
                'in_flight' => [],
                'result_data' => [
                    'c1' => [
                        'toolCallId' => 'c1',
                        'orderIndex' => 0,
                    ],
                ],
                'finalized' => false,
                'max_parallelism' => 1,
                'awaiting_human_input' => [],
            ], 'run-x', 1, 'step-x');
            $this->fail('Expected UnexpectedValueException for missing isError.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('isError', $exception->getMessage());
        }
    }

    public function testMissingRequiredTopLevelKeyIsRejected(): void
    {
        $codec = ToolBatchStateCodecTestFactory::create();
        // awaiting_human_input is the historical soft-default risk; one representative missing key is enough.
        $payload = [
            'expected_order' => [],
            'call_data' => [],
            'pending_queue' => [],
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 1,
        ];

        try {
            $codec->denormalize($payload, 'run-x', 1, 'step-x');
            $this->fail('Expected UnexpectedValueException for missing awaiting_human_input.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public function testNonStringParentModelDegradesToNullWithoutMutatingCallerPayload(): void
    {
        $codec = ToolBatchStateCodecTestFactory::create();

        foreach ([123, ['model' => 'x'], true, false] as $badParentModel) {
            $historical = [
                'expected_order' => ['c1' => 0],
                'call_data' => [
                    'c1' => [
                        'toolCallId' => 'c1',
                        'toolName' => 'read',
                        'orderIndex' => 0,
                        'parentModel' => $badParentModel,
                    ],
                ],
                'pending_queue' => [],
                'in_flight' => [],
                'result_data' => [],
                'finalized' => false,
                'max_parallelism' => 1,
                'awaiting_human_input' => [],
            ];
            $originalParentModel = $historical['call_data']['c1']['parentModel'];

            $batch = $codec->denormalize($historical, 'run-p', 1, 'step-p');
            $this->assertNull($batch->calls['c1']->parentModel);
            $this->assertSame($originalParentModel, $historical['call_data']['c1']['parentModel']);
        }

        $stringHistorical = [
            'expected_order' => ['c1' => 0],
            'call_data' => [
                'c1' => [
                    'toolCallId' => 'c1',
                    'toolName' => 'read',
                    'orderIndex' => 0,
                    'parentModel' => '  keep-exact-string  ',
                ],
            ],
            'pending_queue' => [],
            'in_flight' => [],
            'result_data' => [],
            'finalized' => false,
            'max_parallelism' => 1,
            'awaiting_human_input' => [],
        ];
        $batch = $codec->denormalize($stringHistorical, 'run-p', 1, 'step-p');
        $this->assertSame('  keep-exact-string  ', $batch->calls['c1']->parentModel);
    }
}
