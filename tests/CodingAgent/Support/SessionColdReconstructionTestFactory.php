<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\Replay\SessionColdReconstructionService;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Ineersa\CodingAgent\Tests\Session\History\InMemoryHistoryProjectionStore;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class SessionColdReconstructionTestFactory
{
    public static function create(
        EventStoreInterface $eventStore,
        ?HistoryProjectionStoreInterface $historyStore = null,
        ?ActiveRunContextInterface $activeRunContext = null,
        ?TranscriptProjector $transcriptProjector = null,
        ?RunStateStoreInterface $runStateStore = null,
    ): SessionColdReconstructionService {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        /** @var DenormalizerInterface $denormalizer */
        $denormalizer = $serializer;
        $dispatcher = new EventDispatcher();
        $projectionState = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $transcriptProjector ??= new TranscriptProjector($dispatcher, $projectionState);

        return new SessionColdReconstructionService(
            eventStore: $eventStore,
            historyProjector: new HistoryProjector(),
            historyProjectionStore: $historyStore ?? new InMemoryHistoryProjectionStore(),
            runStateReducer: new RunStateReducer(
                $denormalizer,
                new ToolExecutionEndPayloadCodec($serializer),
            ),
            eventMapper: new RuntimeEventMapper(
                new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer)),
            ),
            transcriptProjector: $transcriptProjector,
            logger: new NullLogger(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            denormalizer: $denormalizer,
            activeRunContext: $activeRunContext,
            runStateStore: $runStateStore,
        );
    }
}
