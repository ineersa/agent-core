<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool\Completion;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBounds;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use Ineersa\AgentCore\Tests\Support\RetainedCompletionTestFactory;
use PHPUnit\Framework\TestCase;

final class RetainedToolCompletionEnvelopeTest extends TestCase
{
    public function testInlineEnvelopeExcludesSourceEventFromFingerprintAndRebindsIt(): void
    {
        $codec = RetainedCompletionTestFactory::codec();
        $factory = RetainedCompletionTestFactory::envelopeFactory();
        $correlation = $this->correlation();
        $normalized = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'is_error' => false,
        ];
        $result = new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolCallId: 'call-1',
            orderIndex: 0,
            result: $normalized,
        );
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
        $without = $envelope->integrity->payloadFingerprint;
        $withSource = $envelope->withAcceptedSourceEventReference(new RetainedToolCompletionSourceEventReference('run-1', 12, 1));
        $this->assertSame($without, $withSource->integrity->payloadFingerprint);
        $this->assertSame(12, $withSource->origin->acceptedSourceEventReference?->seq);
        $roundTrip = $codec->denormalize($codec->normalize($withSource));
        $this->assertSame($codec->normalize($withSource), $codec->normalize($roundTrip));
        $this->assertLessThanOrEqual(
            RetainedToolCompletionBounds::ENVELOPE_MAX_BYTES,
            \strlen(json_encode($codec->normalize($withSource), \JSON_THROW_ON_ERROR)),
        );
    }

    public function testLargeBodyIsSealedAndSubjectSummaryHonorUtf8Bounds(): void
    {
        $store = new FakeRetainedToolCompletionBodyStore();
        $factory = RetainedCompletionTestFactory::envelopeFactory($store);
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
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::SUBJECT_MAX_BYTES, \strlen($envelope->presentation->subject));
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::SUMMARY_MAX_BYTES, \strlen($envelope->presentation->boundedSummary));
        $this->assertTrue($store->isHeld('run-1', 'completion-large'));
    }

    public function testHostAuthoredNativeGenerationBindsWithoutScrapingPresentation(): void
    {
        $factory = RetainedCompletionTestFactory::envelopeFactory();
        $normalized = [
            'tool_name' => 'tasks',
            'content' => [['type' => 'text', 'text' => 'done']],
            'is_error' => false,
            'details' => [
                'native_artifact_handoffs' => [[
                    'parent_run_id' => 'run-1',
                    'artifact_id' => 'agent_abc',
                    'handoff_id' => '0199aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee',
                    'bytes' => 4,
                    'sha256' => hash('sha256', 'body'),
                    'media_type' => 'text/markdown',
                ]],
            ],
        ];
        $envelope = $factory->build(
            completionId: 'completion-native',
            correlation: $this->correlation(),
            result: new ToolCallResult('run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $normalized),
            normalizedToolResult: $normalized,
            controllerRunId: 'run-1',
            executionOwnerKind: 'subagent',
        );
        $this->assertSame(RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff, $envelope->body->kind);
        $this->assertSame('0199aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee', $envelope->body->nativeHandoffId);
        $this->assertSame('native_artifact_handoff', $envelope->attachments[0]['kind']);
    }

    public function testSerializerRejectsWrongOwnerTypes(): void
    {
        $codec = RetainedCompletionTestFactory::codec();
        $factory = RetainedCompletionTestFactory::envelopeFactory();
        $normalized = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'is_error' => false,
        ];
        $envelope = $factory->build(
            completionId: 'completion-types',
            correlation: $this->correlation(),
            result: new ToolCallResult('run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $normalized),
            normalizedToolResult: $normalized,
            controllerRunId: 'run-1',
            executionOwnerKind: 'bash',
        );
        $payload = $codec->normalize($envelope);
        $payload['origin']['origin_turn_no'] = '1';
        $this->expectException(\Throwable::class);
        $codec->denormalize($payload);
    }

    private function correlation(): DeferredToolCompletionCorrelation
    {
        return new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440900',
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
