<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class ResumeSessionInitializerTestFactory
{
    public static function create(EntityManagerInterface $entityManager, string $projectDir): SessionInitializer
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $projectDir,
            sessions: new SessionsConfig(path: '.hatfield/sessions'),
        );

        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $entityManager,
        );

        $eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $sessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );

        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher()),
        );

        $dispatcher = new EventDispatcher();
        $projectionState = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber());
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());
        $dispatcher->addSubscriber(new CancellationProjectionSubscriber());
        $dispatcher->addSubscriber(new RunLifecycleProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, $projectionState);

        $turnTreeProvider = new class implements TurnTreeProviderInterface {
            public function forSession(string $runId): TurnTreeView
            {
                return new TurnTreeView(
                    runId: $runId,
                    nodesByTurnNo: [],
                    rootTurnNos: [],
                    currentLeafTurnNo: null,
                    activePathTurnNos: [],
                );
            }
        };

        return new SessionInitializer(
            sessionStore: $sessionStore,
            eventStore: $eventStore,
            eventMapper: $mapper,
            projector: $projector,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            eventApplier: new TuiRuntimeEventApplier($projector),
            turnTreeProvider: $turnTreeProvider,
            sessionTranscriptProvider: new class implements \Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface {
                public function transcriptForLeaf(string $runId, int $leafTurnNo): \Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO
                {
                    return new \Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO([], []);
                }
            },
        );
    }
}
