<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentLaunchStatusEnum;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After child RunCommit: enqueue durable observation for tracked deferred single subagents.
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

        $committedStatus = RunStatus::tryFrom($context->status) ?? RunStatus::Running;

        try {
            $this->commandBus->dispatch(new ObserveDeferredSingleSubagentChildTurnMessage(
                lifecycleId: $projection->lifecycleId,
                childRunId: $context->runId,
                committedStatus: $committedStatus,
                turnNo: $context->turnNo,
                committedEvents: $context->events,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('deferred_single_subagent.child_turn_dispatch_failed', [
                'lifecycle_id' => $projection->lifecycleId,
                'child_run_id' => $context->runId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.child_turn_dispatch_failed',
                'exception_class' => $e::class,
            ]);
        }

        return $context;
    }
}
