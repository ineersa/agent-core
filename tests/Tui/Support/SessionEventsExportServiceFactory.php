<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Session\Export\EffectiveModelContextProjector;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Ineersa\Tui\Export\SessionEventsExportService;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;

final class SessionEventsExportServiceFactory
{
    public static function create(
        ?ToolboxInterface $toolbox = null,
        ?LoggerInterface $logger = null,
    ): SessionEventsExportService {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();

        return new SessionEventsExportService(
            contextProjector: new EffectiveModelContextProjector(
                eventPayloadNormalizer: new EventPayloadNormalizer(),
                historyReplayFilter: new HistoryReplayFilter(new HistoryProjector()),
                runStateReducer: new RunStateReducer(
                    AttributeSerializerValidatorTestFactory::denormalizer(),
                    new ToolExecutionEndPayloadCodec($serializer),
                ),
            ),
            toolbox: $toolbox,
            logger: $logger,
        );
    }
}
