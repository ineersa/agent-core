<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Fork;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Contract: fork-local session copy is an independent canonical session with rewritten identity;
 * parent RunStore/EventStore/DB/files remain unchanged.
 */
#[CoversClass(ForkSessionCopyService::class)]
final class ForkSessionCopyServiceTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $sessionStore;
    private SessionRunStore $runStore;
    private SessionRunEventStore $eventStore;
    private RunStateRebuilderInterface $rebuilder;
    private ForkSessionCopyService $copyService;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->sessionStore = $container->get(HatfieldSessionStore::class);
        $this->runStore = $container->get(SessionRunStore::class);
        $this->eventStore = $container->get(SessionRunEventStore::class);
        $this->rebuilder = $container->get(RunStateRebuilderInterface::class);
        $this->copyService = $container->get(ForkSessionCopyService::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testCopyCreatesIndependentSessionWithoutMutatingParent(): void
    {
        $parentRunId = $this->sessionStore->createSession('Parent prompt for fork copy');
        $parentSession = $this->sessionStore->findSession($parentRunId);
        $this->assertNotNull($parentSession);

        $parentState = new RunState(
            runId: $parentRunId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 5,
        );
        $this->assertTrue($this->runStore->compareAndSwap($parentState, 0));

        $this->eventStore->append(new RunEvent(
            runId: $parentRunId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: ['model' => 'llama_cpp/test'],
        ));
        $this->eventStore->append(new RunEvent(
            runId: $parentRunId,
            seq: 2,
            turnNo: 1,
            type: 'user_message',
            payload: [
                'content' => 'User task',
                'metadata' => ['compact_summary' => true],
            ],
        ));
        $this->eventStore->append(new RunEvent(
            runId: $parentRunId,
            seq: 3,
            turnNo: 1,
            type: 'assistant_message',
            payload: ['content' => 'Assistant reply'],
        ));
        $this->eventStore->append(new RunEvent(
            runId: $parentRunId,
            seq: 4,
            turnNo: 1,
            type: 'tool_execution_start',
            payload: ['tool_call_id' => 'call_abc', 'tool_name' => 'read'],
        ));
        $this->eventStore->append(new RunEvent(
            runId: $parentRunId,
            seq: 5,
            turnNo: 1,
            type: 'tool_execution_end',
            payload: ['tool_call_id' => 'call_abc', 'is_error' => false],
        ));

        $parentStatePath = $this->sessionStore->resolveSessionsBasePath().'/'.$parentRunId.'/state.json';
        $parentEventsPath = $this->sessionStore->resolveSessionsBasePath().'/'.$parentRunId.'/events.jsonl';
        $parentStateBytesBefore = file_get_contents($parentStatePath);
        $parentEventsBytesBefore = file_get_contents($parentEventsPath);
        $this->assertNotFalse($parentStateBytesBefore);
        $this->assertNotFalse($parentEventsBytesBefore);

        $parentLoadedBefore = $this->runStore->get($parentRunId);
        $this->assertNotNull($parentLoadedBefore);
        $parentEventsBefore = $this->eventStore->allFor($parentRunId);

        $forkLocalRunId = $this->sessionStore->createSession('Fork-local placeholder');
        $this->assertNotSame($parentRunId, $forkLocalRunId);

        try {
            $this->copyService->copyParentSessionToForkLocal($parentRunId, $forkLocalRunId);

            $this->assertSame($parentStateBytesBefore, file_get_contents($parentStatePath));
            $this->assertSame($parentEventsBytesBefore, file_get_contents($parentEventsPath));

            $parentLoadedAfter = $this->runStore->get($parentRunId);
            $this->assertNotNull($parentLoadedAfter);
            $this->assertSame($parentLoadedBefore->version, $parentLoadedAfter->version);
            $this->assertSame($parentLoadedBefore->lastSeq, $parentLoadedAfter->lastSeq);
            $this->assertSame($parentLoadedBefore->status, $parentLoadedAfter->status);

            $parentEventsAfter = $this->eventStore->allFor($parentRunId);
            $this->assertCount(\count($parentEventsBefore), $parentEventsAfter);

            $forkEvents = $this->eventStore->allFor($forkLocalRunId);
            $this->assertCount(5, $forkEvents);
            foreach ($forkEvents as $event) {
                $this->assertSame($forkLocalRunId, $event->runId);
            }

            $forkState = $this->runStore->get($forkLocalRunId);
            $this->assertNotNull($forkState);
            $this->assertSame($forkLocalRunId, $forkState->runId);
            $this->assertSame(1, $forkState->version);

            $forkSession = $this->sessionStore->findSession($forkLocalRunId);
            $this->assertNotNull($forkSession);
            $this->assertSame($parentRunId, $forkSession->parentId);
            $this->assertSame($parentRunId, $forkSession->rootId);
            $this->assertSame($parentSession->prompt, $forkSession->prompt);
            $this->assertSame($parentSession->model, $forkSession->model);

            $replayed = $this->rebuilder->rebuildIfStale($forkState, $forkLocalRunId);
            $this->assertGreaterThan(0, $replayed->eventCount);
        } finally {
            $this->copyService->removeForkLocalSession($forkLocalRunId);
            $this->assertFalse($this->sessionStore->exists($forkLocalRunId));
        }
    }
}
