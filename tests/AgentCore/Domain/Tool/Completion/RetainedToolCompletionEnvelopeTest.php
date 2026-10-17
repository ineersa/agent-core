<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool\Completion;

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
        $outcome = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'is_error' => false,
        ];
        [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $outcome,
        );
        $envelope = $factory->build(
            completionId: 'completion-1',
            correlation: $correlation,
            result: $result,
            normalizedToolResult: $normalized,
            executionOwnerKind: 'bash',
            controllerRunId: 'run-1',
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
        $outcome = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => $large]],
            'is_error' => false,
        ];
        [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $outcome,
        );
        $envelope = $factory->build(
            completionId: 'completion-large',
            correlation: $this->correlation(),
            result: $result,
            normalizedToolResult: $normalized,
            executionOwnerKind: 'bash',
            controllerRunId: 'run-1',
        );
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::SUBJECT_MAX_BYTES, \strlen($envelope->presentation->subject));
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::SUMMARY_MAX_BYTES, \strlen($envelope->presentation->boundedSummary));
        $this->assertTrue($store->isHeld('run-1', 'completion-large'));
    }

    public function testHostAuthoredNativeGenerationBindsWithoutScrapingPresentation(): void
    {
        $factory = RetainedCompletionTestFactory::envelopeFactory();
        $outcome = [
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
        [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $outcome,
        );
        $envelope = $factory->build(
            completionId: 'completion-native',
            correlation: $this->correlation(),
            result: $result,
            normalizedToolResult: $normalized,
            executionOwnerKind: 'subagent',
            controllerRunId: 'run-1',
        );
        $this->assertSame(RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff, $envelope->body->kind);
        $this->assertSame('0199aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee', $envelope->body->nativeHandoffId);
        $this->assertSame('native_artifact_handoff', $envelope->attachments[0]['kind']);
    }

    public function testLargeNativeBatchSealsManifestAndBoundsInlineAttachments(): void
    {
        $store = new FakeRetainedToolCompletionBodyStore();
        $factory = RetainedCompletionTestFactory::envelopeFactory($store);
        $entries = [];
        for ($i = 0; $i < 60; ++$i) {
            $entries[] = [
                'parent_run_id' => 'run-1',
                'artifact_id' => 'agent_'.str_pad((string) $i, 3, '0', \STR_PAD_LEFT),
                'handoff_id' => \sprintf('0199aaaa-bbbb-7ccc-8ddd-%012d', $i),
                'bytes' => 4,
                'sha256' => hash('sha256', 'body-'.$i),
                'media_type' => 'text/markdown',
            ];
        }
        $outcome = [
            'tool_name' => 'tasks',
            'content' => [['type' => 'text', 'text' => 'batch done']],
            'is_error' => false,
            'details' => [
                'native_artifact_handoffs' => $entries,
            ],
        ];
        [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $outcome,
        );
        $envelope = $factory->build(
            completionId: 'completion-native-batch',
            correlation: $this->correlation(),
            result: $result,
            normalizedToolResult: $normalized,
            executionOwnerKind: 'subagent',
            controllerRunId: 'run-1',
        );
        $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
        $this->assertLessThanOrEqual(RetainedToolCompletionBounds::INLINE_NATIVE_ATTACHMENT_MAX, \count($envelope->attachments));
        $this->assertLessThanOrEqual(
            RetainedToolCompletionBounds::ENVELOPE_MAX_BYTES,
            \strlen(json_encode(RetainedCompletionTestFactory::codec()->normalize($envelope), \JSON_THROW_ON_ERROR)),
        );
        $this->assertTrue($store->isHeld('run-1', 'completion-native-batch'));
        $manifest = $store->inspectJson($envelope->body, 'run-1');
        $this->assertSame(60, $manifest['json']['count']);
        $this->assertCount(60, $manifest['json']['entries']);
    }

    public function testSerializerRejectsWrongOwnerTypes(): void
    {
        $codec = RetainedCompletionTestFactory::codec();
        $factory = RetainedCompletionTestFactory::envelopeFactory();
        $outcome = [
            'tool_name' => 'bash',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'is_error' => false,
        ];
        [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
            'run-1', 1, 'step-1', 1, 'key-1', 'call-1', 0, $outcome,
        );
        $envelope = $factory->build(
            completionId: 'completion-types',
            correlation: $this->correlation(),
            result: $result,
            normalizedToolResult: $normalized,
            executionOwnerKind: 'bash',
            controllerRunId: 'run-1',
        );
        $payload = $codec->normalize($envelope);
        $payload['origin']['origin_turn_no'] = '1';
        $this->expectException(\Throwable::class);
        $codec->denormalize($payload);
    }

    public function testScalarAndNullOutcomesRetainCanonicalOuterMessageFingerprint(): void
    {
        $store = new FakeRetainedToolCompletionBodyStore();
        $factory = RetainedCompletionTestFactory::envelopeFactory($store);
        foreach ([null, 'plain-text', 0, false] as $index => $outcome) {
            [$result, $normalized] = RetainedCompletionTestFactory::messageAndToolResult(
                'run-1', 1, 'step-1', 1, 'key-scalar-'.$index, 'call-scalar-'.$index, 0, $outcome,
            );
            $envelope = $factory->build(
                completionId: 'completion-scalar-'.$index,
                correlation: $this->correlation(),
                result: $result,
                normalizedToolResult: $normalized,
                executionOwnerKind: 'bash',
                controllerRunId: 'run-1',
            );
            $this->assertArrayHasKey('result', $normalized);
            $this->assertSame($outcome, $normalized['result']);
            // Scalar/null outcomes are sealed as JSON values; inline body refs remain array-shaped.
            $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed, $envelope->body->kind);
            $this->assertTrue($store->isHeld('run-1', 'completion-scalar-'.$index));
            $inspect = $store->inspectJson($envelope->body, 'run-1');
            $this->assertSame($outcome, $inspect['json']);
        }
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
