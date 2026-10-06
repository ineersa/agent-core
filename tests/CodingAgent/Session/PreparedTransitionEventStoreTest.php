<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class PreparedTransitionEventStoreTest extends IsolatedKernelTestCase
{
    public function testParentCutHidesUnfinalizedSuffixAndPreservesAllocatedSequenceHoles(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('prepared parent');
        $store = $container->get(SessionRunEventStore::class);
        $first = PreparedEventStoreSeeder::append($store, new RunEvent($run, 0, 0, 'run_started', []));
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        $container->get(FileRunSequenceAllocator::class)->allocateBlock(FileRunSequenceAllocator::counterPathForEventsLog($path), 2);
        $action = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::create($run, 0, 'prepared', 'failed');
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
        $store->finalizeTransition($run);
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
        $stream = new StreamingCommittedRuntimeEventStore($store, $container->get(RuntimeEventMapper::class), $sink, true);
        PreparedEventStoreSeeder::append($store, new RunEvent($child, 0, 0, 'run_started', []));
        $this->assertCount(0, iterator_to_array($sink->drain($child)), 'Seeding the inner store does not emit streaming events.');
        $stream->appendTransition([new RunEvent($child, 0, 0, 'agent_end', ['reason' => 'completed'])], ['run_id' => $child, 'predecessor_seq' => 1]);
        $this->assertSame(1, $stream->latestSequenceFor($child));
        $this->assertCount(0, iterator_to_array($sink->drain($child)));
        $this->assertFileExists($sessions->resolveSessionsBasePath().'/'.$parent.'/artifacts/agents/artifact-prepared/events.jsonl.append.pending.json');
        $this->assertDirectoryDoesNotExist($sessions->resolveSessionsBasePath().'/'.$child);
        $stream->finalizeTransition($child);
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
            $this->assertStringContainsString('requires coordination recovery', $exception->getMessage());
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
}
