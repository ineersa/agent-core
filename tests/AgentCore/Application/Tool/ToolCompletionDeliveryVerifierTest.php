<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryVerifier;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Serializer\SerializerInterface;

final class ToolCompletionDeliveryVerifierTest extends IsolatedKernelTestCase
{
    public function testCommittedReferenceRoundTripsAndVerifiesWithoutItsReclaimedBody(): void
    {
        [$runId, $event, $reference] = $this->committedReceipt();
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $wire = $serializer->serialize($reference, 'json');
        $decoded = $serializer->deserialize($wire, ToolCompletionDeliveryReferenceDTO::class, 'json');
        $this->assertEquals($reference, $decoded);
        $this->assertStringContainsString('source_event', $wire);
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $bodyPath = $sessions->resolveSessionsBasePath().'/'.$runId.'/runtime/retained-tool-completions/'.$reference->messageId.'.json';
        $this->assertFileExists($bodyPath);
        unlink($bodyPath);
        $this->assertFileDoesNotExist($bodyPath);

        $verified = $this->verifier()->verify($runId, $reference);
        $this->assertSame($reference->messageId, $verified->messageId);
        $this->assertSame($event->seq, $verified->origin->acceptedSourceEventReference?->seq);
        $this->assertSame($reference->payloadFingerprint, $verified->integrity->payloadFingerprint);
        $this->assertEquals($verified, $this->verifier()->verify($runId, $reference));
    }

    #[DataProvider('invalidSources')]
    public function testRejectsIncorrectCanonicalSource(string $mutation, RetainedToolCompletionFailureCodeEnum $expected): void
    {
        [$runId, $event, $reference] = $this->committedReceipt();
        $payload = $event->payload;
        $type = $event->type;
        $turn = $event->turnNo;
        switch ($mutation) {
            case 'foreground':
                $payload['accepted_completion']['detached'] = false;
                break;
            case 'missing_receipt':
                unset($payload['accepted_completion']);
                break;
            case 'wrong_event_type':
                $type = RunEventTypeEnum::ToolExecutionStart->value;
                break;
            case 'wrong_event_turn':
                ++$turn;
                break;
            case 'wrong_receipt_origin':
                $payload['accepted_completion']['invocation_id'] = 'another-invocation';
                break;
            case 'wrong_locator':
                // The locator is excluded from the semantic fingerprint, but must match exactly.
                $payload['accepted_completion']['retained_message']['origin']['accepted_source_event_reference']['run_id'] = '0'.$runId;
                break;
            case 'wrong_fingerprint':
                $reference = new ToolCompletionDeliveryReferenceDTO($runId, $reference->messageId, $reference->sourceEvent, str_repeat('0', 64));
                break;
            case 'wrong_message':
                $reference = new ToolCompletionDeliveryReferenceDTO($runId, str_repeat('0', 64), $reference->sourceEvent, $reference->payloadFingerprint);
                break;
            case 'foreign_origin':
                $payload['accepted_completion']['retained_message']['origin']['controller_run_id'] = 'foreign';
                break;
            case 'tampered_summary':
                $payload['accepted_completion']['retained_message']['presentation']['bounded_summary'] = 'replacement';
                break;
            case 'unsupported_version':
                $payload['accepted_completion']['retained_message']['schema_version'] = 2;
                break;
            case 'unsupported_producer':
                $payload['accepted_completion']['retained_message']['provenance']['producer'] = 'tool';
                break;
            case 'unsupported_authority':
                $payload['accepted_completion']['retained_message']['provenance']['payload_authority'] = 'human';
                break;
        }
        $events = new InMemoryEventStore();
        $events->seed(new RunEvent($runId, $event->seq, $turn, $type, $payload));
        try {
            (new ToolCompletionDeliveryVerifier($events, self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class), self::getContainer()->get(OwningRootSessionResolverInterface::class)))->verify($runId, $reference);
            $this->fail('Expected invalid delivery source to fail closed.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame($expected, $failure->code());
        }
    }

    /** @return iterable<string, array{string, RetainedToolCompletionFailureCodeEnum}> */
    public static function invalidSources(): iterable
    {
        yield 'attached foreground is not delivery' => ['foreground', RetainedToolCompletionFailureCodeEnum::Unsupported];
        yield 'missing acceptance' => ['missing_receipt', RetainedToolCompletionFailureCodeEnum::Unsupported];
        yield 'wrong event type' => ['wrong_event_type', RetainedToolCompletionFailureCodeEnum::BodyCorrupt];
        yield 'wrong event turn' => ['wrong_event_turn', RetainedToolCompletionFailureCodeEnum::BodyCorrupt];
        yield 'wrong original invocation' => ['wrong_receipt_origin', RetainedToolCompletionFailureCodeEnum::BodyCorrupt];
        yield 'wrong unsigned locator' => ['wrong_locator', RetainedToolCompletionFailureCodeEnum::BodyCorrupt];
        yield 'conflicting fingerprint' => ['wrong_fingerprint', RetainedToolCompletionFailureCodeEnum::Conflict];
        yield 'conflicting message identity' => ['wrong_message', RetainedToolCompletionFailureCodeEnum::Conflict];
        yield 'foreign root' => ['foreign_origin', RetainedToolCompletionFailureCodeEnum::ForeignScope];
        yield 'changed semantic body' => ['tampered_summary', RetainedToolCompletionFailureCodeEnum::Conflict];
        yield 'unsupported schema' => ['unsupported_version', RetainedToolCompletionFailureCodeEnum::Unsupported];
        yield 'unsupported producer' => ['unsupported_producer', RetainedToolCompletionFailureCodeEnum::Unsupported];
        yield 'unsupported authority' => ['unsupported_authority', RetainedToolCompletionFailureCodeEnum::Unsupported];
    }

    public function testUnfinalizedPhysicalAppendIsNotPublishedSourceEvidence(): void
    {
        [$runId, $event, $reference] = $this->committedReceipt();
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $appended = $store->appendTransition([new RunEvent($runId, 0, 1, $event->type, $event->payload)], [
            'run_id' => $runId,
            'predecessor_seq' => $event->seq,
            'effects' => [],
            'actions' => [],
            'post_commit_effects' => [],
            'after_turn_hooks' => false,
        ]);
        $pendingReference = new ToolCompletionDeliveryReferenceDTO($runId, $reference->messageId, new RetainedToolCompletionSourceEventReference($runId, $appended[0]->seq, 1), $reference->payloadFingerprint);
        try {
            $this->verifier()->verify($runId, $pendingReference);
            $this->fail('Expected unpublished physical source to remain unavailable.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::NotFound, $failure->code());
        }
        $pending = $store->verifiedPendingTransition($runId);
        $this->assertNotNull($pending);
        $store->finalizeVerifiedTransition($runId, $pending->identity);
        $this->assertSame($appended[0]->seq, $this->verifier()->verify($runId, $pendingReference)->origin->acceptedSourceEventReference?->seq);
    }

    public function testMissingAndCrossRunReferencesCannotResolve(): void
    {
        [$runId, , $reference] = $this->committedReceipt();
        $missing = new ToolCompletionDeliveryReferenceDTO($runId, $reference->messageId, new RetainedToolCompletionSourceEventReference($runId, 999, 1), $reference->payloadFingerprint);
        try {
            $this->verifier()->verify($runId, $missing);
            $this->fail('Expected missing source.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::NotFound, $failure->code());
        }
        try {
            $this->verifier()->verify('foreign', $reference);
            $this->fail('Expected foreign recipient rejection.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::ForeignScope, $failure->code());
        }
    }

    /** @return array{string, RunEvent, ToolCompletionDeliveryReferenceDTO} */
    private function committedReceipt(): array
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('completion-delivery-source');
        $message = new ToolCallResult($runId, 1, 'step-1', 1, 'key-1', 'call-1', 0, ['content' => [['type' => 'text', 'text' => str_repeat('body-', 2000)]]]);
        $payload = $container->get(ToolExecutionEndPayloadCodec::class)->toEventPayload($message);
        $completionId = ToolInvocationAcceptedCompletionIdentity::completionId($runId, 1, 'step-1', 'call-1');
        $envelope = $container->get(RetainedToolCompletionEnvelopeFactory::class)->build(
            completionId: $completionId,
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440905',
                runId: $runId,
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                toolCallId: 'call-1',
                toolName: 'bash',
                arguments: ['command' => 'true'],
                orderIndex: 0,
                invocationId: 'inv-1',
                executionOwnerKind: 'bash',
            ),
            result: $message,
            normalizedToolResult: $payload['tool_result'],
            executionOwnerKind: 'bash',
        );
        $payload['accepted_completion'] = [
            'completion_id' => $completionId,
            'result_fingerprint' => ToolInvocationAcceptedCompletionIdentity::resultFingerprint($payload['tool_result']),
            'origin_run_id' => $runId,
            'origin_turn_no' => 1,
            'origin_step_id' => 'step-1',
            'tool_call_id' => 'call-1',
            'invocation_id' => 'inv-1',
            'detached' => true,
            'evidence_only' => false,
            'retained_message' => $container->get(RetainedToolCompletionEnvelopeCodec::class)->normalize($envelope),
        ];
        $event = PreparedEventStoreSeeder::append($container->get(PreparedTransitionEventStoreInterface::class), new RunEvent($runId, 0, 1, RunEventTypeEnum::ToolExecutionEnd->value, $payload));

        return [$runId, $event, new ToolCompletionDeliveryReferenceDTO($runId, $completionId, new RetainedToolCompletionSourceEventReference($runId, $event->seq, $event->turnNo), $envelope->integrity->payloadFingerprint)];
    }

    private function verifier(): ToolCompletionDeliveryVerifier
    {
        return new ToolCompletionDeliveryVerifier(self::getContainer()->get(PreparedTransitionEventStoreInterface::class), self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class), self::getContainer()->get(OwningRootSessionResolverInterface::class));
    }
}
