<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunEventProjector;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentRecoveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeliverDeferredSingleSubagentLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\RecoverDeferredSingleSubagentLifecycleMessage;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DeferredSingleSubagentRecoveryTest extends IsolatedKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestDirectoryIsolation::createHatfieldTree((string) getcwd(), withSessions: true);
    }

    public function testGapObservationEnqueuesRecoveryWithoutCursorAdvanceAndRecoveryTailsJsonlOnce(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $parentRunId = 'parent-gap-'.bin2hex(random_bytes(3));
        $childRunId = 'child-gap-'.bin2hex(random_bytes(3));
        $artifactId = 'agent_dddddddddddddddd';
        $deadline = new \DateTimeImmutable('+600 seconds');
        $repo->reserve($parentRunId, 1, 'tool-gap', 0, $childRunId, $artifactId, 'worker', 'gap', null, $deadline);
        $repo->markLaunched($parentRunId, 'tool-gap', new \DateTimeImmutable());
        $launch = $repo->findByParentRunAndToolCall($parentRunId, 'tool-gap');
        $this->assertNotNull($launch);
        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertNotNull($entity);

        $repo->applyChildLifecycleProjection(
            lifecycleId: $launch->lifecycleId,
            projection: \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO::fromArray([
                'child_status' => 'running',
                'child_turn_no' => 1,
                'last_committed_seq' => 1,
                'input_tokens' => 1,
            ]),
            childEventCursor: 1,
            expectedProjectionVersion: $entity->projectionVersion,
        );

        $this->writeChildEventLine($parentRunId, $artifactId, $childRunId, 3, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']);

        $bus = new TestMessageBus();
        $observe = new ObserveDeferredSingleSubagentChildTurnHandler($repo, new DeferredChildRunEventProjector(), new TestLogger(), $bus);
        $observe(new ObserveDeferredSingleSubagentChildTurnMessage(
            $launch->lifecycleId,
            $childRunId,
            RunStatus::Running,
            2,
            [new AfterTurnCommitEventSummary(5, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2])],
        ));

        $entity = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(1, $entity?->childEventCursor);
        $this->assertInstanceOf(RecoverDeferredSingleSubagentLifecycleMessage::class, $bus->messages[0]);

        $recoveryBus = new TestMessageBus();
        $recovery = new DeferredSingleSubagentRecoveryService(
            $repo,
            self::getContainer()->get(AgentChildRunEventStoreFactory::class),
            new DeferredChildRunEventProjector(),
            $recoveryBus,
            new TestLogger(),
        );
        $recovery->recover($launch->lifecycleId);

        $entityAfter = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entityAfter?->childEventCursor);
        $this->assertSame('completed', $entityAfter?->childLifecycleProjection['child_status']);
        $this->assertInstanceOf(DeliverDeferredSingleSubagentLifecycleMessage::class, $recoveryBus->messages[0]);

        $recovery->recover($launch->lifecycleId);
        $entityDup = $repo->findEntityByLifecycleId($launch->lifecycleId);
        $this->assertSame(3, $entityDup?->childEventCursor);
        $this->assertSame('completed', $entityDup?->childLifecycleProjection['child_status']);
    }

    private function writeChildEventLine(string $parentRunId, string $artifactId, string $childRunId, int $seq, string $type, array $payload): void
    {
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: (string) getcwd()),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $resolver = new \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($hatfieldSessionStore));
        $path = $resolver->eventsPath($parentRunId, $artifactId);
        mkdir(\dirname($path), 0775, true);
        $line = (new EventPayloadNormalizer())->normalize($childRunId, $seq, 1, $type, $payload);
        file_put_contents($path, json_encode($line, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
    }
}
