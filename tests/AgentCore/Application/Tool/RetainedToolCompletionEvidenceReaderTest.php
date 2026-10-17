<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEvidenceReader;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

final class RetainedToolCompletionEvidenceReaderTest extends TestCase
{
    public function testReaderResolvesInlineAndOwnedBodiesAndRejectsForeignCorruptUnsupported(): void
    {
        $events = new InMemoryEventStore();
        $bodies = new FakeRetainedToolCompletionBodyStore();
        $factory = new RetainedToolCompletionEnvelopeFactory($bodies);
        $reader = new RetainedToolCompletionEvidenceReader($events, $bodies);

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
        $normalized = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'small']],
            'is_error' => false,
        ];
        $envelope = $factory->build(
            completionId: 'completion-small',
            correlation: $correlation,
            result: new ToolCallResult('run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $normalized),
            normalizedToolResult: $normalized,
            controllerRunId: 'run-1',
            executionOwnerKind: 'bash',
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
                'retained_message' => $envelope->toArray(),
            ],
        ]));

        $resolved = $reader->read('run-1', 'run-1', 'completion-small');
        $this->assertSame('completion-small', $resolved['envelope']->messageId);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::Inline->value, $resolved['body']['kind']);
        $this->assertSame('small', $resolved['body']['inline']['content'][0]['text']);

        try {
            $reader->read('other-root', 'run-1', 'completion-small');
            $this->fail('Expected foreign scope.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }

        try {
            $reader->read('run-1', 'run-1', 'missing');
            $this->fail('Expected not found.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::NotFound, $failure->code());
        }

        $corrupt = $envelope->toArray();
        $corrupt['integrity']['payload_fingerprint'] = str_repeat('b', 64);
        $corrupt['origin']['controller_run_id'] = 'run-2';
        $corrupt['origin']['source_run_id'] = 'run-2';
        $corrupt['message_id'] = 'completion-corrupt';
        $events = new InMemoryEventStore();
        $events->seed(new RunEvent('run-2', 1, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
            'tool_result' => $normalized,
            'accepted_completion' => [
                'completion_id' => 'completion-corrupt',
                'result_fingerprint' => 'x',
                'origin_run_id' => 'run-2',
                'origin_turn_no' => 1,
                'origin_step_id' => 'step-1',
                'tool_call_id' => 'call-1',
                'invocation_id' => 'inv-1',
                'detached' => true,
                'evidence_only' => false,
                'retained_message' => $corrupt,
            ],
        ]));
        $reader = new RetainedToolCompletionEvidenceReader($events, $bodies);
        try {
            $reader->read('run-2', 'run-2', 'completion-corrupt');
            $this->fail('Expected corrupt fingerprint failure.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, $failure->code());
        }
    }
}
