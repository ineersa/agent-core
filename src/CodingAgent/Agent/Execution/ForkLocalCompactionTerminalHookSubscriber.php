<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Agent\Execution\Messenger\ContinueForkAfterCompactionMessage;
use Ineersa\CodingAgent\Agent\Fork\ForkLocalCompactionSessionService;
use Ineersa\CodingAgent\Compaction\CompactionSkipReasonEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Observes terminal compaction events on fork-local sessions and enqueues one continuation.
 */
final readonly class ForkLocalCompactionTerminalHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private SubagentRunMetadataReader $metadataReader,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if ([] === $context->events) {
            return $context;
        }

        $metadata = $this->metadataReader->readRunStartedMetadata($context->runId);
        $session = \is_array($metadata['session'] ?? null) ? $metadata['session'] : null;
        if (!\is_array($session) || ForkLocalCompactionSessionService::SESSION_KIND !== ($session['kind'] ?? null)) {
            return $context;
        }

        $terminal = $this->findFirstTerminal($context);
        if (null === $terminal) {
            return $context;
        }

        try {
            $this->commandBus->dispatch(new ContinueForkAfterCompactionMessage(
                forkLocalRunId: $context->runId,
                success: $terminal['success'],
                failureReason: $terminal['failureReason'],
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('fork_local_compaction.terminal_dispatch_failed', [
                'run_id' => $context->runId,
                'component' => 'agent.execution',
                'event_type' => 'fork_local_compaction.terminal_dispatch_failed',
                'exception_class' => $e::class,
            ]);
        }

        return $context;
    }

    /**
     * @return array{success: bool, failureReason: ?string}|null
     */
    private function findFirstTerminal(AfterTurnCommitHookContext $context): ?array
    {
        foreach ($context->events as $event) {
            if (RunEventTypeEnum::ContextCompacted->value === $event->type) {
                return ['success' => true, 'failureReason' => null];
            }

            if (RunEventTypeEnum::ContextCompactionFailed->value !== $event->type) {
                continue;
            }

            $reason = \is_string($event->payload['reason'] ?? null) ? $event->payload['reason'] : null;
            if (null !== $reason && null !== CompactionSkipReasonEnum::tryFrom($reason)) {
                // Structural no-op: continue launch with existing local messages.
                return ['success' => true, 'failureReason' => null];
            }

            return ['success' => false, 'failureReason' => $reason ?? 'compaction_hard_failure'];
        }

        return null;
    }
}
