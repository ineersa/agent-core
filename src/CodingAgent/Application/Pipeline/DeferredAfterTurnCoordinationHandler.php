<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DeferredAfterTurnCoordinationHandler implements CoordinationActionValidatorInterface
{
    public function __construct(private PreparedTransitionEventStoreInterface $store, private MessageBusInterface $commandBus)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(DeferredAfterTurnCoordinationDTO $action): void
    {
        $this->validate($action);
        $pending = $this->store->verifiedPendingTransition($action->runId);
        if (null === $pending || ($pending->work['predecessor_seq'] ?? null) !== $action->predecessorSequence
            || !array_any([...($pending->work['actions'] ?? []), ...($pending->work['after_turn_actions'] ?? [])], static fn (object $captured): bool => $captured instanceof DeferredAfterTurnCoordinationDTO && serialize($captured) === serialize($action))) {
            throw new \RuntimeException('After-turn coordination requires its verified owner transition.');
        }
        if ($action->message instanceof ObserveDeferredSubagentBatchChildTurnMessage
            && array_map(static fn ($event): int => $event->seq, $action->message->committedEvents) !== $pending->eventSequences) {
            throw new \RuntimeException('Child observation differs from the allocated canonical sequences.');
        }
        $this->commandBus->dispatch($action->message);
    }

    public function supports(object $action): bool
    {
        return $action instanceof DeferredAfterTurnCoordinationDTO;
    }

    public function validate(object $action): void
    {
        if (!$action instanceof DeferredAfterTurnCoordinationDTO || '' === $action->runId || $action->predecessorSequence < 0) {
            throw new \RuntimeException('Invalid after-turn coordination identity.');
        }
        if ($action->message instanceof ObserveDeferredSubagentBatchChildTurnMessage && ($action->message->childRunId !== $action->runId || [] === $action->message->committedEvents)) {
            throw new \RuntimeException('Invalid child observation owner.');
        }
    }
}
