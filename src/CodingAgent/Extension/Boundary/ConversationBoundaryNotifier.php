<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Boundary;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Psr\Log\LoggerInterface;

/**
 * Best-effort post-commit notifier for terminal conversation boundaries.
 *
 * Must never throw out of notifyPersistedBatch(): callers use it after
 * canonical persistence and must not roll back commits on hook failure.
 *
 * @internal
 */
final readonly class ConversationBoundaryNotifier
{
    public function __construct(
        private ConversationBoundaryProjector $projector,
        private ExtensionHookRegistry $hookRegistry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<RunEvent> $persistedEvents
     */
    public function notifyPersistedBatch(string $runId, array $persistedEvents): void
    {
        try {
            $boundary = $this->projector->projectFromPersistedBatch($runId, $persistedEvents);
        } catch (\Throwable $e) {
            $this->logger->warning('extension.conversation_boundary_project_failed', [
                'component' => 'extension_conversation_boundary',
                'event_type' => 'conversation_boundary_project_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'exception_class' => $e::class,
            ]);

            return;
        }

        if (null === $boundary) {
            return;
        }

        foreach ($this->hookRegistry->afterConversationBoundaryHooks() as $hook) {
            try {
                $hook->afterConversationBoundary($boundary);
            } catch (\Throwable $e) {
                $this->logger->warning('extension.conversation_boundary_hook_failed', [
                    'component' => 'extension_conversation_boundary',
                    'event_type' => 'conversation_boundary_hook_failed',
                    'run_id' => $runId,
                    'session_id' => $runId,
                    'boundary_id' => $boundary->boundaryId,
                    'outcome' => $boundary->outcome->value,
                    'source_start_seq' => $boundary->sourceStartSeq,
                    'source_end_seq' => $boundary->sourceEndSeq,
                    'latest_committed_seq' => $boundary->latestCommittedSeq,
                    'hook' => $hook::class,
                    'exception_class' => $e::class,
                ]);
            }
        }
    }
}
