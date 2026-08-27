<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;

final class ToolExecutionEndPayloadCodecTest extends TestCase
{
    public function testRoundTripsCompleteTypedToolResultThroughCanonicalPayload(): void
    {
        [$serializer] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);
        $codec = new ToolExecutionEndPayloadCodec($serializer);
        $pendingHumanInput = new PendingHumanInputRequestDTO(
            questionId: 'question-1',
            continuationKind: HumanInputContinuationKindEnum::ToolCall,
            payload: [
                'question_id' => 'question-1',
                'prompt' => 'Approve the attachment?',
                'attachments' => [['id' => 'attachment-1', 'kind' => 'image']],
            ],
            continuationRef: [
                'run_id' => 'run-1',
                'turn_no' => 3,
                'step_id' => 'step-1',
                'tool_call_id' => 'call-1',
            ],
        );
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 3,
            stepId: 'step-1',
            attempt: 2,
            idempotencyKey: 'result-key-1',
            toolCallId: 'call-1',
            orderIndex: 4,
            result: [
                'tool_name' => 'inspect_attachment',
                'content' => [
                    ['type' => 'text', 'text' => 'Attachment requires approval.'],
                    ['type' => 'image', 'image_url' => ['url' => 'artifact://attachment-1']],
                ],
                'details' => [
                    'attachments' => [['id' => 'attachment-1', 'sha256' => 'abc123']],
                    'provider' => ['trace_id' => 'trace-1'],
                ],
            ],
            isError: true,
            error: ['code' => 'approval_required', 'message' => 'Approval required before continuing.'],
            pendingHumanInput: $pendingHumanInput,
        );

        $decoded = $codec->fromEventPayload($codec->toEventPayload($result));

        $this->assertSame('run-1', $decoded->runId());
        $this->assertSame(3, $decoded->turnNo());
        $this->assertSame('step-1', $decoded->stepId());
        $this->assertSame(2, $decoded->attempt());
        $this->assertSame('result-key-1', $decoded->idempotencyKey());
        $this->assertSame('call-1', $decoded->toolCallId);
        $this->assertSame(4, $decoded->orderIndex);
        $this->assertSame($result->result, $decoded->result);
        $this->assertTrue($decoded->isError);
        $this->assertSame($result->error, $decoded->error);
        $this->assertInstanceOf(PendingHumanInputRequestDTO::class, $decoded->pendingHumanInput);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $decoded->pendingHumanInput->continuationKind);
        $this->assertSame($pendingHumanInput->payload, $decoded->pendingHumanInput->payload);
        $this->assertSame($pendingHumanInput->continuationRef, $decoded->pendingHumanInput->continuationRef);
    }

    public function testRejectsMissingCanonicalToolResultPayload(): void
    {
        [$serializer] = AttributeSerializerValidatorTestFactory::create(withBackedEnumNormalizer: true);
        $codec = new ToolExecutionEndPayloadCodec($serializer);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('ToolExecutionEnd payload must contain an array tool_result.');

        $codec->fromEventPayload([]);
    }
}
