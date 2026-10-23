<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\DeferredToolCompletion;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Entity\ToolBatchCall;
use Ineersa\CodingAgent\Entity\ToolBatchSchedule;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DeferredToolCompletionRepositoryTest extends IsolatedKernelTestCase
{
    #[DataProvider('completionBoundaries')]
    public function testObsoleteCompletionSettlesMetadataAndRemovesRetainedBatchWithoutBodies(bool $producer): void
    {
        [$runId, $reference, $directory] = $this->fixture(false);
        $container = self::getContainer();
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $metadata = $repository->findMetadataByDeferredId('deferred');
        $this->assertNotNull($metadata);
        $this->assertSame('pending', $metadata->status);
        $batches = $container->get(ToolBatchStoreInterface::class);
        $batches->delete($runId, 1, 'step');
        $this->assertNotNull($batches->loadSchedule($runId, 1, 'step'), 'Pending deferred metadata retains the settled batch.');
        $this->assertDirectoryExists($directory);

        $state = new RunState($runId, RunStatus::Cancelled, turnNo: 1);
        $bus = $this->bus($state);
        $message = $producer ? new CompleteDeferredToolCall('deferred', [['type' => 'text', 'text' => 'discard']])
            : new Envelope(new ToolPayloadMessage($reference, 'deferred'), [new ReceivedStamp('run_control')]);
        $bus->dispatch($message);
        $this->assertSame('completed', $repository->status('deferred'));
        $this->assertNull($batches->loadSchedule($runId, 1, 'step'));
        $this->assertSame(0, (int) $container->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM tool_batch_call WHERE run_id = ?', [$runId]));
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertNotNull($repository->findMetadataByDeferredId('deferred'), 'Completed identity survives body cleanup.');
        $bus->dispatch($message);
        $this->assertSame('completed', $repository->status('deferred'), 'Duplicate completion is idempotent after cleanup.');
    }

    #[DataProvider('completionBoundaries')]
    public function testMissingActiveBodyIsNotSilentlySettled(bool $producer): void
    {
        [$runId, $reference, $directory] = $this->fixture(true);
        $state = new RunState($runId, RunStatus::Running, turnNo: 1, pendingToolCalls: ['call' => false], activeStepId: 'step');
        $bus = $this->bus($state, expectNoBodyRead: false);
        $message = $producer ? new CompleteDeferredToolCall('deferred', [])
            : new Envelope(new ToolPayloadMessage($reference, 'deferred'), [new ReceivedStamp('run_control')]);
        $this->expectException(ToolPayloadException::class);
        $this->expectExceptionMessage('Active tool data is missing.');
        try {
            $bus->dispatch($message);
        } finally {
            $this->assertSame('pending', self::getContainer()->get(DeferredToolCompletionRepository::class)->status('deferred'));
            $this->assertDirectoryExists($directory);
        }
    }

    /** @return iterable<array{bool}> */
    public static function completionBoundaries(): iterable
    {
        yield 'producer' => [true];
        yield 'receiver' => [false];
    }

    public function testStoredMetadataMustMatchReferenceWithoutRequiringItsBody(): void
    {
        [$runId] = $this->fixture(false);
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('tool_deferred_completion', ['step_id' => 'wrong-step'], ['deferred_id' => 'deferred']);
        $this->expectException(ToolPayloadException::class);
        $this->expectExceptionMessage('Deferred correlation reference does not match its index.');
        self::getContainer()->get(DeferredToolCompletionRepository::class)->findMetadataByDeferredId('deferred');
    }

    /** @return array{string, ToolPayloadReferenceDTO, string} */
    private function fixture(bool $active): array
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('isolated deferred completion');
        $reference = new ToolPayloadReferenceDTO('correlation', $runId, 1, 'step', 1, 'key', 'call', 0, 'subagent', str_repeat('a', 64), 100);
        $serializer = $container->get(SerializerInterface::class);
        $completion = new DeferredToolCompletion();
        $completion->deferredId = 'deferred';
        $completion->runId = $runId;
        $completion->turnNo = 1;
        $completion->stepId = 'step';
        $completion->attempt = 1;
        $completion->idempotencyKey = 'key';
        $completion->toolCallId = 'call';
        $completion->toolName = 'subagent';
        $completion->bodyReferenceJson = $serializer->serialize($reference, 'json');
        $completion->invocationReferenceJson = $serializer->serialize(new ToolPayloadReferenceDTO('call', $runId, 1, 'step', 1, 'key', 'call', 0, 'subagent', str_repeat('b', 64), 100), 'json');
        $header = new ToolBatchSchedule();
        $header->runId = $runId;
        $header->turnNo = 1;
        $header->stepId = 'step';
        $header->finalized = !$active;
        $header->expectedOrderJson = '{"call":0}';
        $header->inFlightJson = $active ? '["call"]' : '[]';
        $header->awaitingHumanInputJson = '[]';
        $call = new ToolBatchCall();
        $call->runId = $runId;
        $call->turnNo = 1;
        $call->stepId = 'step';
        $call->toolCallId = 'call';
        $callReference = new ToolPayloadReferenceDTO('call', $runId, 1, 'step', 1, 'key', 'call', 0, 'subagent', str_repeat('b', 64), 100);
        $call->bodyReferenceJson = $serializer->serialize($callReference, 'json');
        $manager = $container->get(EntityManagerInterface::class);
        foreach ([$completion, $header, $call] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $base = \dirname($container->get(ToolBatchRunStoragePathsInterface::class)->resolveToolBatchesDirectory($runId));
        $directory = $base.'/tool-payloads/'.hash('sha256', 'call');
        // This is an unrelated cleanup sentinel. Neither referenced body exists.
        $container->get(Filesystem::class)->dumpFile($directory.'/sentinel.txt', 'retained');
        $resultReference = new ToolPayloadReferenceDTO('result', $runId, 1, 'step', 1, 'key', 'call', 0, 'subagent', str_repeat('c', 64), 100);

        return [$runId, $resultReference, $directory];
    }

    private function bus(RunState $state, bool $expectNoBodyRead = true): MessageBus
    {
        $container = self::getContainer();
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->loadRecovered($state);
        $replay = $this->createStub(RunStateRebuilderInterface::class);
        $replay->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt($state));
        $batches = $container->get(ToolBatchStoreInterface::class);
        $locks = $container->get(RunLockManager::class);
        $payloads = $container->get(ToolPayloadStoreInterface::class);
        if ($expectNoBodyRead) {
            $payloads = $this->createMock(ToolPayloadStoreInterface::class);
            $payloads->expects($this->never())->method('read');
            $payloads->expects($this->never())->method('publish');
        }
        $commands = new TestMessageBus();
        $logger = new TestLogger();
        $events = $this->createStub(EventStoreInterface::class);
        $events->method('latestSequenceFor')->willReturn($state->lastSeq);
        $fence = $container->get(\Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence::class);

        return new MessageBus([new ToolPayloadMiddleware(
            $payloads, new ToolPayloadAdmission($batches, $registry, $replay, $events, $logger, $container->get(CanonicalToolInvocationReader::class), $repository, $payloads, $fence), $repository, $locks,
            $container->get(ValidatorInterface::class), $commands, $logger,
            new CompleteDeferredToolCallHandler($repository, $commands, $locks, $batches, $payloads, $fence, $logger, null, null),
        )]);
    }
}
