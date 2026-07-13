<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * After a parent run commit enters Cancelling/Cancelled, enqueue durable parent-cancel interruptions
 * for active deferred single-child lifecycles. No RunStore reads.
 */
final readonly class DeferredSingleSubagentParentCancelHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if (!\in_array($context->status, [RunStatus::Cancelling->value, RunStatus::Cancelled->value], true)) {
            return $context;
        }

        $active = $this->launchRepository->findActiveByParentRunId($context->runId);
        foreach ($active as $projection) {
            try {
                $this->commandBus->dispatch(new InterruptDeferredSingleSubagentMessage(
                    $projection->lifecycleId,
                    DeferredSingleSubagentInterruptionKindEnum::ParentCancelled,
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to enqueue deferred single subagent parent cancellation.', previous: $exception);
            }
        }

        return $context;
    }
}
