<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool\Completion;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyReference;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionOrigin;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use PHPUnit\Framework\TestCase;

final class RetainedToolCompletionEnvelopeTest extends TestCase
{
    public function testInlineEnvelopeExcludesSourceEventFromFingerprintAndRebindsIt(): void
    {
        $factory = new RetainedToolCompletionEnvelopeFactory();
        $correlation = $this->correlation();
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-1',
            orderIndex: 0,
            result: ['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'ok']]],
        );
        $normalized = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'is_error' => false,
        ];
        $envelope = $factory->build(
            completionId: 'completion-1',
            correlation: $correlation,
            result: $result,
            normalizedToolResult: $normalized,
            controllerRunId: 'run-1',
            executionOwnerKind: 'bash',
        );
        $this->assertSame('completion-1', $envelope->messageId);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::Inline, $envelope->body->kind);
        $without = $envelope->payloadFingerprint;
        $withSource = $envelope->withAcceptedSourceEventReference(new RetainedToolCompletionSourceEventReference('run-1', 12, 1));
        $this->assertSame($without, $withSource->payloadFingerprint);
        $this->assertSame(12, $withSource->origin->acceptedSourceEventReference?->seq);
        $roundTrip = RetainedToolCompletionEnvelope::fromArray($withSource->toArray());
        $this->assertSame($withSource->toArray(), $roundTrip->toArray());
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::ENVELOPE_MAX_BYTES, \strlen(json_encode($withSource->toArray(), \JSON_THROW_ON_ERROR)));
    }

    public function testLargeBodyIsSealedAndSubjectSummaryHonorUtf8Bounds(): void
    {
        $store = new FakeRetainedToolCompletionBodyStore();
        $factory = new RetainedToolCompletionEnvelopeFactory($store);
        $large = str_repeat('字', 3000);
        $normalized = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => $large]],
            'is_error' => false,
        ];
        $envelope = $factory->build(
            completionId: 'completion-large',
            correlation: $this->correlation(),
            result: new ToolCallResult('run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $normalized),
            normalizedToolResult: $normalized,
            controllerRunId: 'run-1',
            executionOwnerKind: 'bash',
        );
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::SUBJECT_MAX_BYTES, \strlen($envelope->subject));
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::SUMMARY_MAX_BYTES, \strlen($envelope->boundedSummary));
        $this->assertTrue($store->exists($envelope->body, 'run-1'));
        $read = $store->readJson($envelope->body, 'run-1');
        $this->assertSame($normalized['content'][0]['text'], $read['json']['content'][0]['text']);
    }

    public function testUnsupportedKindIsTypedFailureAndForeignBodyIsRejected(): void
    {
        $origin = new RetainedToolCompletionOrigin('run-1', 'run-1', 1, 'step-1', 'call-1', 'inv-1', 'bash');
        $body = RetainedToolCompletionBodyReference::inline(
            inline: ['ok' => true],
            mediaType: 'application/json',
            bytes: 11,
            sha256: hash('sha256', '{"ok":true}'),
        );
        $payload = (new RetainedToolCompletionEnvelope(
            messageId: 'm1',
            origin: $origin,
            terminalOutcome: 'completed',
            subject: 'bash completed',
            boundedSummary: 'bash completed.',
            body: $body,
            payloadFingerprint: str_repeat('a', 64),
        ))->toArray();
        $payload['kind'] = 'agent_notice';
        try {
            RetainedToolCompletionEnvelope::fromArray($payload);
            $this->fail('Expected unsupported kind failure.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::Unsupported, $failure->code());
        }

        $store = new FakeRetainedToolCompletionBodyStore();
        $ref = $store->sealJson('run-1', 'body-1', '{"a":1}', 'application/json');
        try {
            $store->readJson($ref, 'run-other');
            $this->fail('Expected foreign scope failure.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }
    }

    private function correlation(): DeferredToolCompletionCorrelation
    {
        return new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440801',
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
    }
}
