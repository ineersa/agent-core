<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentLaunchStatusEnum;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After child RunCommit: enqueue durable observation for tracked deferred single subagents.
 *
 * Runs synchronously inside RunCommit; dispatch is fire-and-forget to run_control.
 */
final readonly class DeferredSingleSubagentChildTurnHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if ([] === $context->events) {
            return $context;
        }

        $projection = $this->launchRepository->findByChildRunId($context->runId);
        if (null === $projection) {
            return $context;
        }

        if (DeferredSingleSubagentLaunchStatusEnum::Failed === $projection->launchStatus) {
            return $context;
        }

        try {
            $this->commandBus->dispatch(new ObserveDeferredSingleSubagentChildTurnMessage(
                lifecycleId: $projection->lifecycleId,
                childRunId: $context->runId,
                committedStatus: $context->status,
                turnNo: $context->turnNo,
                committedEvents: $context->events,
            ));
        } catch (ExceptionInterface $e) {
            $this->logger->warning('deferred_single_subagent.child_turn_dispatch_failed', [
                'lifecycle_id' => $projection->lifecycleId,
                'child_run_id' => $context->runId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.child_turn_dispatch_failed',
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $context;
    }
}
