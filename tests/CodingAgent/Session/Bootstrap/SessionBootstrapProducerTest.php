<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Bootstrap;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Application\Message\AttachRun;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Replay\SessionReplayCoordinator;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SessionBootstrapProducerTest extends PerMethodIsolatedKernelTestCase
{
    public function testConfiguredColdAttachBuildsOnceThenSealsThePostAttachCutWithoutStartingATurn(): void
    {
        $container = static::getContainer();
        $logger = new TestLogger();
        $coordinator = new SessionReplayCoordinator($container->get(RunHistorySourceProviderInterface::class), $container->get(RunStateReducer::class), $container->get(RuntimeEventMapper::class), $container->get('owner.replay.transcript_projector'), $logger, $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class));
        $container->set(SessionReplayCoordinator::class, $coordinator);
        $container->set(\Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler::class, new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            $container->get(InMemoryRuntimeEventSink::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false, $logger, $container->get(ActiveRunContextInterface::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $container->get(HatfieldSessionStore::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            $container->get(SessionBootstrapProducer::class),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
        ));
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('bootstrap owner');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::appendMany($events, [
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'old prompt']]]]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'old-step']),
            RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed']),
        ]);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->release($run);
        $bus = $container->get('agent.command.bus');
        $bus->dispatch(new AttachRun($run, [new AgentMessage('system', [['type' => 'text', 'text' => 'fresh instructions']])], 'bootstrap-attach'), [new ReceivedStamp('run_control')]);
        $published = iterator_to_array($container->get(InMemoryRuntimeEventSink::class)->drain($run));
        $available = array_values(array_filter($published, static fn ($event): bool => RuntimeEventTypeEnum::BootstrapAvailable->value === $event->type));
        $this->assertCount(1, $available);
        $this->assertSame(0, $available[0]->seq, 'Transfer readiness is not cursor evidence.');
        $cut = $available[0]->payload;
        $this->assertSame($registry->requireLoaded($run)->lastSeq, $cut['canonical_seq']);
        $this->assertGreaterThan(3, $cut['canonical_seq'], 'Freeze occurs after refresh, not after initial recovery.');
        $this->assertSame(filesize($sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl'), $cut['end_offset']);
        $this->assertSame(1, $registry->requireLoaded($run)->turnNo);
        $this->assertNull($registry->requireLoaded($run)->currentOperation);
        $this->assertCount(1, array_filter($logger->records, static fn ($record): bool => 'session_replay.reconstructed' === $record['message']));
        $this->assertSame([], $container->get('owner.replay.transcript_projector')->blocks());
        $body = file_get_contents($sessions->resolveSessionsBasePath().'/'.$run.'/runtime/bootstrap/'.$cut['bootstrap_id'].'.jsonl');
        $this->assertNotFalse($body);
        $this->assertStringContainsString('old prompt', $body);
        $this->assertStringNotContainsString('RunState', $body);
        $container->get(SessionBootstrapSpoolStore::class)->cancel($run);
    }

    public function testWarmPreparationKeepsTheExactOwnerStateAndReleasesTemporaryProjection(): void
    {
        $container = static::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('warm bootstrap');
        PreparedEventStoreSeeder::appendMany($container->get(PreparedTransitionEventStoreInterface::class), [
            RunEvent::forAppend($run, 0, 'run_started', []),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1]),
            RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed']),
        ]);
        $producer = $container->get(SessionBootstrapProducer::class);
        $producer->prepare($run);
        $producer->seal();
        $owner = $container->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame($owner, $producer->prepare($run));
        $descriptor = $producer->seal();
        $this->assertSame($owner, $container->get(ActiveRunContextInterface::class)->requireLoaded($run));
        $this->assertSame([], $container->get('owner.replay.transcript_projector')->blocks());
        $container->get(SessionBootstrapSpoolStore::class)->acknowledge($descriptor);
    }

    public function testOwnerAndSpoolMemoryPlateausInSeparate128MProcesses(): void
    {
        $directory = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('owner-bootstrap-memory');
        try {
            $measurements = [];
            foreach ([256, 4096] as $count) {
                $paths = \Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv::allocateIsolatedPaths(\dirname(__DIR__, 4), $directory.'/'.$count, 'owner-bootstrap');
                // Castor already migrated this schema. Each probe gets its own copy,
                // never the parent test's open DAMA transaction or shared queue.
                $schema = static::getContainer()->get('doctrine.dbal.default_connection')->getParams()['path'];
                (new \Symfony\Component\Filesystem\Filesystem())->copy($schema, $paths['appAbsolute']);
                $process = new \Symfony\Component\Process\Process([\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Support/OwnerBootstrapMemory.php', $directory, (string) $count], \dirname(__DIR__, 4), [
                    'HATFIELD_SESSION_ID' => false,
                    'HATFIELD_TEST_DATABASE_PATH' => $paths['appEnv'],
                    'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $paths['transportEnv'],
                ], timeout: 8);
                $process->mustRun();
                $measurements[] = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            }
            [$small, $large] = $measurements;
            fwrite(\STDERR, 'owner bootstrap memory: '.json_encode($measurements, \JSON_THROW_ON_ERROR)."\n");
            $this->assertGreaterThan($small['archive_bytes'] * 15, $large['archive_bytes']);
            $this->assertLessThanOrEqual($small['peak_bytes'] + 4 * 1024 * 1024, $large['peak_bytes']);
            foreach ($measurements as $measurement) {
                $this->assertLessThan(128 * 1024 * 1024, $measurement['peak_bytes']);
                $this->assertSame($measurement['archive_bytes'], $measurement['indexed_reads']['index_cold_rebuild']);
                $this->assertSame($measurement['archive_bytes'], $measurement['indexed_reads']['selected_history']);
                $this->assertLessThanOrEqual(SessionBootstrapSpoolStore::MAX_BYTES, $measurement['spool_bytes']);
                $this->assertLessThanOrEqual(2001, $measurement['spool_records']);
                $this->assertGreaterThan($measurement['archive_bytes'], $measurement['end_offset']);
                $this->assertSame($measurement['count'] * 9 + 2, $measurement['canonical_seq']);
            }
        } finally {
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
