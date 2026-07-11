<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Psr\Log\LoggerInterface;

final readonly class SessionRepairService
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStoreInterface $runStore,
        private RunStateReducer $runStateReducer,
        private ReplayEventPreparer $replayEventPreparer,
        private EventFactory $eventFactory,
        private EventPayloadNormalizer $eventPayloadNormalizer,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
    ) {
    }

    public function repair(string $runId, bool $apply): RepairResult
    {
        throw new \LogicException('Not implemented');
    }
}
