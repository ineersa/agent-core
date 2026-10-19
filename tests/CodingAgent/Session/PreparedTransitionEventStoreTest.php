<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Process\Process;

final class PreparedTransitionEventStoreTest extends IsolatedKernelTestCase
{
    public static function reuseModes(): iterable
    {
        yield 'hot commit' => ['commit'];
        yield 'hot recovery' => ['hot-recovery'];
        yield 'cold recovery' => ['cold-recovery'];
    }

    #[DataProvider('reuseModes')]
    public function testConfiguredPipelineDecodesCapturedWorkOnce(string $mode): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('decode reuse');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $summary = new AfterTurnCommitEventSummary(0, 'run_started', []);
        $action = new DeferredAfterTurnCoordinationDTO($run, 0,
            new ObserveDeferredSubagentBatchChildTurnMessage(
                'decode-batch', 1, $run, RunStatus::Running, 1, [$summary],
            ),
        );
        $effect = new ExecuteLlmStep($run, 1, 'decode-step', 1, 'decode-key', 'tools', [
            new AgentMessage('user', [['type' => 'text', 'text' => 'context']], metadata: ['probe' => new Support\JournalWorkDecodeCounter()]),
        ]);
        $event = new RunEvent($run, 0, 0, 'run_started', []);
        Support\JournalWorkDecodeCounter::$decodes = 0;
        if ('commit' === $mode) {
            $state = RunState::queued($run);
            $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
            $container->get(RunCommit::class)->commit(
                $state, $state->with(['status' => RunStatus::Running]), [$event], [$effect], false, postCommitActions: [$action],
            );
        } else {
            // Appending through the durable inner store simulates loss of the hot stream batch.
            $writer = 'cold-recovery' === $mode ? $container->get(ChildAwareEventStore::class) : $store;
            $writer->appendTransition([$event], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$effect], 'after_turn_actions' => [$action]]);
            $this->assertNull($store->latestSequenceFor($run));
            $container->get(PendingTransitionRecovery::class)->recover($run);
        }
        $this->assertSame(1, Support\JournalWorkDecodeCounter::$decodes);
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame(1, $store->latestSequenceFor($run));
    }

    public static function changedEvidence(): iterable
    {
        foreach (['manifest', 'work', 'stage', 'archive'] as $part) {
            yield $part.' before coordination' => [$part, true];
            yield $part.' before finalization' => [$part, false];
        }
    }

    #[DataProvider('changedEvidence')]
    public function testReusedEvidenceRefusesPhysicalChanges(string $part, bool $coordination): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('changed evidence');
        $inner = $container->get(SessionRunEventStore::class);
        $sink = new InMemoryRuntimeEventSink();
        $store = new StreamingCommittedRuntimeEventStore($inner, $container->get(RuntimeEventMapper::class), $sink, true, $container->get('event_dispatcher'));
        $summary = new AfterTurnCommitEventSummary(0, 'run_started', []);
        $action = new DeferredAfterTurnCoordinationDTO($run, 0,
            new ObserveDeferredSubagentBatchChildTurnMessage(
                'evidence-batch', 1, $run, RunStatus::Running, 1, [$summary],
            ),
        );
        $store->appendTransition([new RunEvent($run, 0, 0, 'run_started', [])], ['run_id' => $run, 'predecessor_seq' => 0, 'after_turn_actions' => [$action]]);
        $verified = $store->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        if ('manifest' === $part) {
            $manifest = json_decode(file_get_contents($path.'.append.pending.json'), true, flags: \JSON_THROW_ON_ERROR);
            $manifest['boundary_hash'] = str_repeat('0', 64);
            file_put_contents($path.'.append.pending.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        } elseif ('archive' === $part) {
            $bytes = file_get_contents($path);
            rename($path, $path.'.replaced');
            file_put_contents($path, $bytes);
        } else {
            file_put_contents($path.('work' === $part ? '.append.work' : '.append.staged'), 'changed', \FILE_APPEND);
        }
        clearstatcache();
        try {
            if ($coordination) {
                $container->get('agent.command.bus')->dispatch($verified->work['after_turn_actions'][0], [new HandlerArgumentsStamp([$verified])]);
            } else {
                $store->finalizeVerifiedTransition($run, $verified);
            }
            $this->fail('Changed physical evidence cannot use an earlier verification.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(match ($part) {
                'manifest' => 'identity changed',
                'work' => 'work is missing or corrupt',
                'stage' => 'bytes are missing or corrupt',
                'archive' => 'predecessor or tail is inconsistent',
            }, $exception->getMessage());
        }
        $this->assertFileExists($path.'.append.pending.json');
        $this->assertCount(0, iterator_to_array($sink->drain($run)));
    }

    public function testParentCutHidesUnfinalizedSuffixAndPreservesAllocatedSequenceHoles(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('prepared parent');
        $store = $container->get(SessionRunEventStore::class);
        $first = PreparedEventStoreSeeder::append($store, new RunEvent($run, 0, 0, 'run_started', []));
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        $container->get(FileRunSequenceAllocator::class)->allocateBlock(FileRunSequenceAllocator::counterPathForEventsLog($path), 2);
        $action = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::create($run, 0, 'prepared');
        $persisted = $store->appendTransition([new RunEvent($run, 0, 0, 'agent_end', ['reason' => 'completed'])], [
            'run_id' => $run, 'predecessor_seq' => $first->seq, 'actions' => [$action], 'effects' => [], 'post_commit_effects' => [], 'after_turn_hooks' => false,
        ]);
        $this->assertSame(4, $persisted[0]->seq);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->assertCount(1, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX)));
        $this->assertCount(1, iterator_to_array($store->reverseFor($run)));
        $manifest = json_decode(file_get_contents($path.'.append.pending.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('actions', $manifest, 'Pending manifest references work instead of embedding it.');
        $this->assertArrayNotHasKey('messages', $manifest);
        /** @var SerializerInterface $serializer */
        $serializer = $container->get('messenger.transport.native_php_serializer');
        $encoded = json_decode(file_get_contents($path.'.append.work'), true, flags: \JSON_THROW_ON_ERROR);
        $restored = $serializer->decode($encoded)->getMessage();
        $this->assertInstanceOf(\Ineersa\CodingAgent\Session\PendingTransitionWorkDTO::class, $restored);
        $this->assertSame($action->message->stepId(), $restored->work['actions'][0]->message->stepId());
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $store->finalizeVerifiedTransition($run, $pending);
        $this->assertSame(4, $store->latestSequenceFor($run));
        $this->assertCount(2, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX)));
    }

    public function testChildTransitionUsesParentArtifactPathAndStreamingWaitsForFinalization(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $parent = $sessions->createSession('prepared child');
        $child = 'child-prepared';
        $store = $container->get(AgentChildRunEventStoreFactory::class)->create($parent, $child, 'artifact-prepared');
        $sink = new InMemoryRuntimeEventSink();
        $stream = new StreamingCommittedRuntimeEventStore($store, $container->get(RuntimeEventMapper::class), $sink, true, $container->get('event_dispatcher'));
        PreparedEventStoreSeeder::append($store, new RunEvent($child, 0, 0, 'run_started', []));
        $this->assertCount(0, iterator_to_array($sink->drain($child)), 'Seeding the inner store does not emit streaming events.');
        $stream->appendTransition([new RunEvent($child, 0, 0, 'agent_end', ['reason' => 'completed'])], ['run_id' => $child, 'predecessor_seq' => 1]);
        $this->assertSame(1, $stream->latestSequenceFor($child));
        $this->assertCount(0, iterator_to_array($sink->drain($child)));
        $this->assertFileExists($sessions->resolveSessionsBasePath().'/'.$parent.'/artifacts/agents/artifact-prepared/events.jsonl.append.pending.json');
        $this->assertDirectoryDoesNotExist($sessions->resolveSessionsBasePath().'/'.$child);
        $pending = $stream->verifiedPendingTransition($child);
        $this->assertNotNull($pending);
        $stream->finalizeVerifiedTransition($child, $pending);
        $this->assertSame(2, $stream->latestSequenceFor($child));
        $this->assertCount(1, iterator_to_array($sink->drain($child)));
    }

    public function testColdOwnerReconcilesPhysicalSuffixButRefusesReplayBeforeCoordinationRecovery(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('unfinished owner');
        $store = $container->get(SessionRunEventStore::class);
        PreparedEventStoreSeeder::append($store, new RunEvent($run, 0, 0, 'run_started', []));
        $store->appendTransition([new RunEvent($run, 0, 0, 'agent_end', ['reason' => 'completed'])], ['run_id' => $run, 'predecessor_seq' => 1, 'effects' => [new \stdClass()]]);
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        $manifest = json_decode(file_get_contents($path.'.append.pending.json'), true, flags: \JSON_THROW_ON_ERROR);
        $handle = fopen($path, 'r+b');
        try {
            $this->assertTrue(ftruncate($handle, $manifest['offset'] + 7));
        } finally {
            fclose($handle);
        }
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->release($run);
        try {
            $container->get(OwnerRunInitializationMiddleware::class)->initializeForOwner($run, new AdvanceRun($run, 0, 'next', 1, 'next'));
            $this->fail('Unresolved coordination must block owner admission.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unsupported owner transition message.', $exception->getMessage());
        }
        clearstatcache(true, $path);
        $this->assertSame($manifest['offset'] + $manifest['length'], filesize($path));
        $this->assertFileExists($path.'.append.pending.json');
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->expectException(RunContextNotLoadedException::class);
        $registry->requireLoaded($run);
    }

    public function testWrongPredecessorRefusesBeforeAllocatingOrWriting(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('wrong predecessor');
        $store = $container->get(SessionRunEventStore::class);
        PreparedEventStoreSeeder::append($store, new RunEvent($run, 0, 0, 'run_started', []));
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        $counter = FileRunSequenceAllocator::counterPathForEventsLog($path);
        $before = file_get_contents($counter);
        try {
            $store->appendTransition([new RunEvent($run, 0, 0, 'agent_end', [])], ['run_id' => $run, 'predecessor_seq' => 0]);
            $this->fail('A stale owner must not append.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('predecessor sequence changed', $exception->getMessage());
        }
        $this->assertSame($before, file_get_contents($counter));
        $this->assertFileDoesNotExist($path.'.append.pending.json');
        $this->assertSame(1, $store->latestSequenceFor($run));
    }

    public function testSeparateProcessColdFinalizationPublishesOrdinaryEventBatchWithoutPublicCutBypass(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('cold ordinary publish');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::append($store, new RunEvent($run, 0, 0, 'run_started', []));
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        $container->get(FileRunSequenceAllocator::class)->allocateBlock(FileRunSequenceAllocator::counterPathForEventsLog($path), 1);
        $persisted = $store->appendTransition([
            new RunEvent($run, 0, 0, 'agent_end', ['reason' => 'completed']),
        ], ['run_id' => $run, 'predecessor_seq' => 1]);
        $this->assertSame([3], array_map(static fn (RunEvent $event): int => $event->seq, $persisted));
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertSame([3], $pending->eventSequences);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->assertSame([1], array_map(static fn (RunEvent $event): int => $event->seq, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX))));
        $beforeBytes = file_get_contents($path);
        $beforeManifest = file_get_contents($path.'.append.pending.json');
        $beforeWork = file_get_contents($path.'.append.work');
        $beforeStage = file_get_contents($path.'.append.staged');

        $process = new Process([
            \PHP_BINARY,
            __DIR__.'/Support/FinalizeVerifiedStreamingTransition.php',
            getcwd(),
            $run,
            $pending->identity,
        ], env: ['HATFIELD_SESSION_ID' => false]);
        $process->setTimeout(8);
        $process->mustRun();
        $payload = json_decode(trim($process->getOutput()), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['count']);
        $this->assertSame([3], $payload['seqs']);
        $this->assertSame(['run.completed'], $payload['types']);
        $this->assertSame(3, $payload['latest']);
        $this->assertSame([1, 3], $payload['range']);
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame(3, $store->latestSequenceFor($run));
        $this->assertSame([1, 3], array_map(static fn (RunEvent $event): int => $event->seq, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX))));
        $this->assertSame($beforeBytes, file_get_contents($path));
        $this->assertFileDoesNotExist($path.'.append.pending.json');
        $this->assertFileDoesNotExist($path.'.append.work');
        $this->assertFileDoesNotExist($path.'.append.staged');
        $this->assertNotFalse($beforeManifest);
        $this->assertNotFalse($beforeWork);
        $this->assertNotFalse($beforeStage);
    }

    public function testSeparateProcessRecoveryDeliversGatedEffectsOnlyAfterFinalization(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('cold gated recovery');
        /** @var PreparedTransitionEventStoreInterface $store */
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::append($store, new RunEvent($run, 0, 0, 'run_started', []));
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        $container->get(FileRunSequenceAllocator::class)->allocateBlock(FileRunSequenceAllocator::counterPathForEventsLog($path), 2);
        $summary = new AfterTurnCommitEventSummary(0, 'agent_end', ['reason' => 'completed']);
        $observation = new ObserveDeferredSubagentBatchChildTurnMessage(
            'batch-cold',
            1,
            $run,
            RunStatus::Completed,
            1,
            [$summary],
        );
        $action = new DeferredAfterTurnCoordinationDTO($run, 1, $observation);
        $request = new ExecuteLlmStep($run, 1, 'llm-cold', 1, 'llm-cold-key', 'tools');
        $persisted = $store->appendTransition([
            new RunEvent($run, 0, 0, 'agent_end', ['reason' => 'completed']),
        ], [
            'run_id' => $run,
            'predecessor_seq' => 1,
            'effects' => [$request],
            'after_turn_actions' => [$action],
        ]);
        $this->assertSame([4], array_map(static fn (RunEvent $event): int => $event->seq, $persisted));
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertSame([4], $pending->eventSequences);
        $this->assertSame(4, $pending->work['after_turn_actions'][0]->message->committedEvents[0]->seq);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->assertSame([1], array_map(static fn (RunEvent $event): int => $event->seq, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX))));
        $manifest = json_decode((string) file_get_contents($path.'.append.pending.json'), true, flags: \JSON_THROW_ON_ERROR);
        $handle = fopen($path, 'r+b');
        $this->assertIsResource($handle);
        try {
            $this->assertTrue(ftruncate($handle, $manifest['offset'] + 12));
        } finally {
            fclose($handle);
        }
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->assertSame([1], array_map(static fn (RunEvent $event): int => $event->seq, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX))));
        $order = [];
        $cutVisibleDuringObservation = null;
        $failure = $this->createMock(MessageBusInterface::class);
        $failure->expects($this->atLeastOnce())->method('dispatch')->willReturnCallback(static function (object $message) use (&$order, &$cutVisibleDuringObservation, $store, $run): \Symfony\Component\Messenger\Envelope {
            if ($message instanceof \Symfony\Component\Messenger\Envelope) {
                $message = $message->getMessage();
            }
            $order[] = $message::class;
            if ($message instanceof ExecuteLlmStep) {
                throw new \RuntimeException('gated delivery must wait for finalization');
            }
            if ($message instanceof DeferredAfterTurnCoordinationDTO
                || $message instanceof ObserveDeferredSubagentBatchChildTurnMessage) {
                $cutVisibleDuringObservation = null !== $store->verifiedPendingTransition($run);
            }

            return new \Symfony\Component\Messenger\Envelope($message);
        });
        $recovery = new PendingTransitionRecovery(
            $store,
            $container->get(ActiveRunContextInterface::class),
            TestTransitionFinalizerFactory::create(
                $store,
                new \Ineersa\AgentCore\Application\Handler\StepDispatcher($failure, $failure, new \Ineersa\AgentCore\Tests\Support\TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
                batches: $container->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
                commands: $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class),
            ),
            $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );
        // Delivery failures after journal finalization are local degradation: the
        // pending cut is cleared; a lost continuation requires explicit repair.
        $recovery->recover($run);

        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame(4, $store->latestSequenceFor($run));
        $this->assertSame([1, 4], array_map(static fn (RunEvent $event): int => $event->seq, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX))));
        $this->assertContains(DeferredAfterTurnCoordinationDTO::class, $order);
        $this->assertSame(ExecuteLlmStep::class, $order[array_key_last($order)]);
        $this->assertTrue($cutVisibleDuringObservation);

        $publishedBytes = file_get_contents($path);
        $process = new Process([
            \PHP_BINARY,
            __DIR__.'/Support/RecoverPendingTransition.php',
            getcwd(),
            $run,
        ], env: ['HATFIELD_SESSION_ID' => false]);
        $process->setTimeout(8);
        $process->mustRun();
        $this->assertSame("recovered\n", $process->getOutput());
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame(4, $store->latestSequenceFor($run));
        $this->assertSame([1, 4], array_map(static fn (RunEvent $event): int => $event->seq, iterator_to_array($store->rangeFor($run, 1, \PHP_INT_MAX))));
        $this->assertSame($publishedBytes, file_get_contents($path));
    }
}
