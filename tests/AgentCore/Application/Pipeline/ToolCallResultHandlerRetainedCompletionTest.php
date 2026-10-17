<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEvidenceReader;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionBodyKindEnum;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedNativeArtifactHandoffReader;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRetainedToolCompletionBodyStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\RetainedOutputCapBodyPromoter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Uid\Uuid;

final class ToolCallResultHandlerRetainedCompletionTest extends IsolatedKernelTestCase
{
    public function testDetachedAcceptanceRetainsImmutableEnvelopeFromCanonicalMessage(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('retained-inline');
        [$handler, $collector, $batches, $deferred] = $this->handlerStack();
        $call = $this->bashCall($runId, 'call-retained', 'call-retained-key');
        TestToolBatchRegistration::register($collector, $batches, $runId, 1, 'tools-old', [$call]);
        $this->backgroundAcknowledge($deferred, $runId, $call, 'bash', new ToolInvocationOwnerReference('bash', 'record-retained', 9, '/tmp/retained.log'));

        $message = ToolCallResultBuilder::success($runId)
            ->withTurnNo(1)
            ->withStepId('tools-old')
            ->withIdempotencyKey('call-retained-key')
            ->withToolCallId('call-retained')
            ->withOrderIndex(0)
            ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']]])
            ->build();

        $result = $handler->handle(
            $message,
            RunStateBuilder::running($runId)->withTurnNo(2)->withActiveStepId('tools-new')->withLastSeq(10)->withPendingToolCalls([])->build(),
        );

        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ToolExecutionEnd->value, $result->events[0]->type);
        $accepted = $result->events[0]->payload['accepted_completion'];
        $this->assertSame($accepted['completion_id'], $accepted['retained_message']['message_id']);
        $this->assertSame('tool_completion', $accepted['retained_message']['kind']);
        $this->assertSame('runtime', $accepted['retained_message']['provenance']['producer']);
        $this->assertStringContainsString('done', $accepted['retained_message']['presentation']['bounded_summary']);
        $this->assertArrayNotHasKey('accepted_source_event_reference', $accepted['retained_message']['origin']);
        $this->assertSame(RetainedToolCompletionBodyKindEnum::Inline->value, $accepted['retained_message']['body']['kind']);
        $this->assertSame('done', $accepted['retained_message']['body']['inline']['content'][0]['text']);
    }

    public function testDetachedAcceptancePromotesRealOutputCapAndNativeBodiesThroughCleanup(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $runId = $sessions->createSession('retained-promotion');
        /** @var SessionRetainedToolCompletionBodyStore $bodyStore */
        $bodyStore = self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class);
        /** @var AgentArtifactRegistry $artifacts */
        $artifacts = self::getContainer()->get(AgentArtifactRegistry::class);
        /** @var RetainedToolCompletionEnvelopeCodec $codec */
        $codec = self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class);

        $tmp = TestDirectoryIsolation::createOsTempDir('retained-handler-output-cap');
        try {
            $outputCapConfig = new OutputCapConfig(storageDir: $tmp, defaultCap: 32);
            $outputCap = new OutputCap($outputCapConfig, new LockFactory(new FlockStore()), new \Psr\Log\NullLogger());
            $fullOutput = str_repeat('FULL-CAPPED-BODY-', 20);
            $capResult = $outputCap->capIfNeeded($fullOutput, $runId);
            $this->assertNotNull($capResult);
            $this->assertFileExists($capResult->savedPath);

            [$handler, $collector, $batches, $deferred] = $this->handlerStack(
                outputCapPromoter: new RetainedOutputCapBodyPromoter($outputCapConfig, $bodyStore),
            );
            $call = $this->bashCall($runId, 'call-output-cap', 'call-output-cap-key');
            TestToolBatchRegistration::register($collector, $batches, $runId, 1, 'tools-old', [$call]);
            $this->backgroundAcknowledge($deferred, $runId, $call, 'bash', new ToolInvocationOwnerReference('bash', 'record-output-cap', 11, '/tmp/output-cap.log'));

            $message = ToolCallResultBuilder::success($runId)
                ->withTurnNo(1)
                ->withStepId('tools-old')
                ->withIdempotencyKey('call-output-cap-key')
                ->withToolCallId('call-output-cap')
                ->withOrderIndex(0)
                ->withResult([
                    'tool_name' => 'bash',
                    'content' => [['type' => 'text', 'text' => 'capped preview']],
                    'details' => [
                        'output_cap' => [
                            'capped' => true,
                            'saved_path' => $capResult->savedPath,
                            'cap' => $capResult->cap,
                            'char_count' => $capResult->charCount,
                            'token_estimate' => $capResult->tokenEstimate,
                        ],
                    ],
                ])
                ->build();

            $accepted = $handler->handle(
                $message,
                RunStateBuilder::running($runId)->withTurnNo(2)->withActiveStepId('tools-new')->withLastSeq(4)->withPendingToolCalls([])->build(),
            )->events[0]->payload['accepted_completion'];

            $this->assertStringContainsString('capped preview', $accepted['retained_message']['presentation']['bounded_summary']);
            $this->assertSame(RetainedToolCompletionBodyKindEnum::OwnedSealed->value, $accepted['retained_message']['body']['kind']);
            $this->assertTrue($bodyStore->isHeld($runId, $accepted['completion_id']));
            $outputCap->cleanupRun($runId, 'shutdown');
            $this->assertFileDoesNotExist($capResult->savedPath);
            $inspect = $bodyStore->inspectJson(
                $codec->denormalize($accepted['retained_message'])->body,
                $runId,
            );
            $this->assertFalse($inspect['truncated']);
            $this->assertStringContainsString('FULL-CAPPED-BODY-', $inspect['text'] ?? '');

            $artifactId = 'agent_native_'.bin2hex(random_bytes(3));
            $artifacts->create($runId, $artifactId, 'child-native-'.bin2hex(random_bytes(3)), 'scout', AgentArtifactKindEnum::Subagent);
            $generation = $artifacts->writeHandoff($runId, $artifactId, str_repeat('NATIVE-HANDOFF-', 40), summary: 'native');

            $call2 = new ExecuteToolCall(
                runId: $runId,
                turnNo: 1,
                stepId: 'tools-native',
                attempt: 1,
                idempotencyKey: 'call-native-key',
                toolCallId: 'call-native',
                toolName: 'tasks',
                args: ['tasks' => [['task' => 'x']]],
                orderIndex: 0,
                mode: 'sequential',
            );
            TestToolBatchRegistration::register($collector, $batches, $runId, 1, 'tools-native', [$call2]);
            $this->backgroundAcknowledge(
                $deferred,
                $runId,
                $call2,
                'subagent',
                new ToolInvocationOwnerReference('subagent', 'record-native', null, null),
            );

            $accepted2 = $handler->handle(
                ToolCallResultBuilder::success($runId)
                    ->withTurnNo(1)
                    ->withStepId('tools-native')
                    ->withIdempotencyKey('call-native-key')
                    ->withToolCallId('call-native')
                    ->withOrderIndex(0)
                    ->withResult([
                        'tool_name' => 'tasks',
                        'content' => [['type' => 'text', 'text' => 'native done']],
                        'details' => [
                            'native_artifact_handoffs' => [[
                                'parent_run_id' => $generation->parentRunId,
                                'artifact_id' => $generation->artifactId,
                                'handoff_id' => $generation->handoffId,
                                'bytes' => $generation->bytes,
                                'sha256' => $generation->sha256,
                                'media_type' => $generation->mediaType,
                            ]],
                        ],
                    ])
                    ->build(),
                RunStateBuilder::running($runId)->withTurnNo(2)->withActiveStepId('tools-new')->withLastSeq(8)->withPendingToolCalls([])->build(),
            )->events[0]->payload['accepted_completion'];

            $this->assertStringContainsString('native done', $accepted2['retained_message']['presentation']['bounded_summary']);
            $this->assertSame(RetainedToolCompletionBodyKindEnum::NativeArtifactHandoff->value, $accepted2['retained_message']['body']['kind']);
            $this->assertSame($generation->handoffId, $accepted2['retained_message']['body']['native_handoff_id']);
            $this->assertSame($generation->artifactId, $accepted2['retained_message']['attachments'][0]['reference']['artifact_id']);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    public function testAllocatedSourceEventLetsEvidenceReaderResolveRetainedBodyWithStableFingerprint(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('retained-seq-reader');
        [$handler, $collector, $batches, $deferred] = $this->handlerStack();
        $call = $this->bashCall($runId, 'call-seq', 'call-seq-key');
        TestToolBatchRegistration::register($collector, $batches, $runId, 1, 'tools-old', [$call]);
        $this->backgroundAcknowledge($deferred, $runId, $call, 'bash', new ToolInvocationOwnerReference('bash', 'record-seq', 3, '/tmp/seq.log'));

        $draft = $handler->handle(
            ToolCallResultBuilder::success($runId)
                ->withTurnNo(1)
                ->withStepId('tools-old')
                ->withIdempotencyKey('call-seq-key')
                ->withToolCallId('call-seq')
                ->withOrderIndex(0)
                ->withResult(['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'sequenced']]])
                ->build(),
            RunStateBuilder::running($runId)->withTurnNo(2)->withActiveStepId('tools-new')->withLastSeq(10)->withPendingToolCalls([])->build(),
        )->events[0];

        $fingerprintBefore = $draft->payload['accepted_completion']['retained_message']['integrity']['payload_fingerprint'];
        $this->assertArrayNotHasKey('accepted_source_event_reference', $draft->payload['accepted_completion']['retained_message']['origin']);

        /** @var PreparedTransitionEventStoreInterface $events */
        $events = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $eventsPath = getcwd().'/.hatfield/sessions/'.$runId.'/events.jsonl';
        // IsolatedKernelTestCase already isolates project root; seed predecessor/cursor gap.
        TestDirectoryIsolation::ensureDirectory(\dirname($eventsPath));
        file_put_contents($eventsPath, '{"schema_version":"1.0","run_id":"'.$runId.'","seq":99,"turn_no":0,"type":"run_started","payload":[]}'."\n");
        file_put_contents(FileRunSequenceAllocator::counterPathForEventsLog($eventsPath), "105\n");

        $persisted = PreparedEventStoreSeeder::append($events, new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: 1,
            type: RunEventTypeEnum::ToolExecutionEnd->value,
            payload: $draft->payload,
        ));
        $this->assertSame(106, $persisted->seq);
        $reference = $persisted->payload['accepted_completion']['retained_message']['origin']['accepted_source_event_reference'];
        $this->assertSame(['run_id' => $runId, 'seq' => 106, 'turn_no' => 1], $reference);
        $this->assertSame(
            $fingerprintBefore,
            $persisted->payload['accepted_completion']['retained_message']['integrity']['payload_fingerprint'],
        );

        $reader = new RetainedToolCompletionEvidenceReader(
            self::getContainer()->get(ChildAwareEventStore::class),
            self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class),
            self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class),
            new FakeRetainedNativeArtifactHandoffReader(),
        );
        $resolved = $reader->read($runId, $runId, $persisted->payload['accepted_completion']['completion_id'], acceptedSourceSeq: 106);
        $this->assertSame('sequenced', $resolved['body']['inline']['content'][0]['text']);
        $this->assertSame($fingerprintBefore, $resolved['envelope']->integrity->payloadFingerprint);
        $this->assertSame(106, $resolved['envelope']->origin->acceptedSourceEventReference?->seq);

        // Prepared recovery: interrupt after append, then finalize the verified pending identity.
        $recoveryRun = self::getContainer()->get(HatfieldSessionStore::class)->createSession('retained-seq-recovery');
        $recoveryPath = getcwd().'/.hatfield/sessions/'.$recoveryRun.'/events.jsonl';
        TestDirectoryIsolation::ensureDirectory(\dirname($recoveryPath));
        file_put_contents($recoveryPath, '{"schema_version":"1.0","run_id":"'.$recoveryRun.'","seq":1,"turn_no":0,"type":"run_started","payload":[]}'."\n");
        $events->appendTransition([
            new RunEvent($recoveryRun, 0, 1, RunEventTypeEnum::TurnAdvanced->value, []),
        ], [
            'run_id' => $recoveryRun,
            'predecessor_seq' => 1,
            'effects' => [],
            'actions' => [],
            'post_commit_effects' => [],
            'after_turn_hooks' => false,
        ]);
        $pending = $events->verifiedPendingTransition($recoveryRun);
        $this->assertNotNull($pending);
        $events->finalizeVerifiedTransition($recoveryRun, $pending->identity);
        $this->assertNull($events->verifiedPendingTransition($recoveryRun));
        $this->assertSame(2, $events->latestSequenceFor($recoveryRun));

        // Shared root/child canonical allocation through ChildAwareEventStore.
        $childRunId = 'child-seq-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_child_seq_'.bin2hex(random_bytes(3));
        self::getContainer()->get(AgentArtifactRegistry::class)->create(
            $runId,
            $artifactId,
            $childRunId,
            'scout',
            AgentArtifactKindEnum::Subagent,
        );
        $childEventsPath = getcwd().'/.hatfield/sessions/'.$runId.'/artifacts/agents/'.$artifactId.'/events.jsonl';
        TestDirectoryIsolation::ensureDirectory(\dirname($childEventsPath));
        file_put_contents($childEventsPath, '{"schema_version":"1.0","run_id":"'.$childRunId.'","seq":99,"turn_no":0,"type":"run_started","payload":[]}'."\n");
        file_put_contents(FileRunSequenceAllocator::counterPathForEventsLog($childEventsPath), "105\n");
        $childPersisted = PreparedEventStoreSeeder::append($events, new RunEvent(
            runId: $childRunId,
            seq: 0,
            turnNo: 1,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: [],
        ));
        $this->assertSame(106, $childPersisted->seq);
    }

    /**
     * @return array{0: ToolCallResultHandler, 1: ToolBatchCollector, 2: ToolBatchStoreInterface, 3: DeferredToolCompletionRepositoryInterface}
     */
    private function handlerStack(?RetainedOutputCapBodyPromoter $outputCapPromoter = null): array
    {
        $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $collector = new ToolBatchCollector($batches, 4, $deferred);
        if (null === $outputCapPromoter) {
            $handler = new ToolCallResultHandler(
                toolBatchCollector: $collector,
                eventFactory: self::getContainer()->get(\Ineersa\AgentCore\Domain\Event\EventFactory::class),
                toolCallExtractor: self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor::class),
                messageNormalizer: self::getContainer()->get(\Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer::class),
                serializer: self::getContainer()->get('serializer'),
                retainedCompletionFactory: self::getContainer()->get(\Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory::class),
                retainedCompletionCodec: self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class),
            );
        } else {
            // Rebuild only the OutputCap promoter/storage root while keeping the configured
            // Serializer, native promoter, body store, and codec.
            $handler = new ToolCallResultHandler(
                toolBatchCollector: $collector,
                eventFactory: self::getContainer()->get(\Ineersa\AgentCore\Domain\Event\EventFactory::class),
                toolCallExtractor: self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor::class),
                messageNormalizer: self::getContainer()->get(\Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer::class),
                serializer: self::getContainer()->get('serializer'),
                retainedCompletionFactory: new \Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory(
                    self::getContainer()->get(SessionRetainedToolCompletionBodyStore::class),
                    self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class),
                    self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface::class),
                    $outputCapPromoter,
                    self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\RetainedNativeHandoffBodyPromoterInterface::class),
                    self::getContainer()->get('logger'),
                ),
                retainedCompletionCodec: self::getContainer()->get(RetainedToolCompletionEnvelopeCodec::class),
            );
        }

        return [$handler, $collector, $batches, $deferred];
    }

    private function bashCall(string $runId, string $toolCallId, string $idempotencyKey): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: 'tools-old',
            attempt: 1,
            idempotencyKey: $idempotencyKey,
            toolCallId: $toolCallId,
            toolName: 'bash',
            args: ['command' => 'true'],
            orderIndex: 0,
            mode: 'sequential',
        );
    }

    private function backgroundAcknowledge(
        DeferredToolCompletionRepositoryInterface $deferred,
        string $runId,
        ExecuteToolCall $call,
        string $ownerKind,
        ToolInvocationOwnerReference $owner,
    ): void {
        $base = new DeferredToolCompletionCorrelation(
            deferredId: Uuid::v7()->toRfc4122(),
            runId: $runId,
            turnNo: $call->turnNo(),
            stepId: $call->stepId(),
            attempt: $call->attempt(),
            idempotencyKey: $call->idempotencyKey(),
            toolCallId: $call->toolCallId,
            toolName: $call->toolName,
            arguments: $call->args,
            orderIndex: $call->orderIndex,
            mode: $call->mode,
            invocationId: 'inv-'.$call->toolCallId,
            controllerRunId: $runId,
            executionOwnerKind: $ownerKind,
            executionOwnerReference: $owner,
            originalExecutionMode: $call->mode,
        );
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));
    }
}
