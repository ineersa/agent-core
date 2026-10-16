<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\ActiveRunContext;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ActiveRunContextTest extends PerMethodIsolatedKernelTestCase
{
    public function testKnownReservedRunIsNotImplicitlyQueuedByLookup(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('registry');
        $context = self::getContainer()->get(ActiveRunContextInterface::class);
        $this->expectException(RunContextNotLoadedException::class);
        $context->requireLoaded($run);
    }

    public function testExplicitCreationAndRecoveryRemainDistinctAndLookupIsMemoryOnly(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('registry');
        $context = self::getContainer()->get(ActiveRunContextInterface::class);
        $created = $context->createNew($run);
        $this->assertSame(RunStatus::Queued, $created->status);
        $this->assertSame($created, $context->requireLoaded($run));
        $context->release($run);
        $recovered = new RunState($run, RunStatus::Completed, lastSeq: 4);
        $context->loadRecovered($recovered);
        $this->assertSame($recovered, $context->requireLoaded($run));
        $this->assertSame($recovered, $context->requireLoaded($run));
        $repository = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $this->assertSame(RunStatus::Completed, $repository->findOperationalStatus($run)?->status);
    }

    public function testUnreservedCreationIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unreserved run');
        self::getContainer()->get(ActiveRunContextInterface::class)->createNew('unknown-registry-run');
    }

    public function testPublicationFailureLeavesRecoveryRequiredInsteadOfOldStateOrQueuedState(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('registry');
        $context = self::getContainer()->get(ActiveRunContextInterface::class);
        $context->createNew($run);
        try {
            $context->replaceCurrent(new RunState($run, RunStatus::Completed, activeStepId: str_repeat('x', 256)));
            $this->fail('Invalid projection must fail publication.');
        } catch (ValidationFailedException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->expectException(RunContextNotLoadedException::class);
        $context->requireLoaded($run);
    }

    public function testPopulationCheckpointsCountResidentEntriesWithoutHotReplaceSpam(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $parentId = $sessions->createSession('registry-parent');
        $entry = self::getContainer()->get(AgentArtifactRegistry::class)->create(
            $parentId,
            'agent_registry_child',
            'registry-child',
            'scout',
            AgentArtifactKindEnum::Subagent,
        );
        self::getContainer()->get(AgentChildRunDirectory::class)->register($entry);

        $logger = new TestLogger();
        $context = new ActiveRunContext(
            self::getContainer()->get(RunOperationalProjectionRepository::class),
            $sessions,
            self::getContainer()->get(AgentChildRunDirectory::class),
            self::getContainer()->get(EventStoreInterface::class),
            new ProcessMemorySnapshotLogger($logger),
        );

        $context->createNew($parentId);
        $context->createNew($entry->agentRunId);
        $context->replaceCurrent($context->requireLoaded($parentId)->with([
            'status' => RunStatus::Running,
            'version' => 1,
        ]));
        $context->release($entry->agentRunId);

        $checkpoints = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'process.memory.checkpoint' === ($record['message'] ?? null),
        ));
        $this->assertCount(3, $checkpoints);

        $first = $checkpoints[0]['context'];
        $this->assertSame('owner.registry.retained', $first['event_type']);
        $this->assertSame('owner_registry', $first['component']);
        $this->assertSame(1, $first['registry_state_count']);
        $this->assertSame(1, $first['registry_parent_count']);
        $this->assertSame(0, $first['registry_child_count']);

        $second = $checkpoints[1]['context'];
        $this->assertSame('owner.registry.retained', $second['event_type']);
        $this->assertSame(2, $second['registry_state_count']);
        $this->assertSame(1, $second['registry_parent_count']);
        $this->assertSame(1, $second['registry_child_count']);

        $third = $checkpoints[2]['context'];
        $this->assertSame('owner.registry.released', $third['event_type']);
        $this->assertSame(1, $third['registry_state_count']);
        $this->assertSame(1, $third['registry_parent_count']);
        $this->assertSame(0, $third['registry_child_count']);
    }
}
