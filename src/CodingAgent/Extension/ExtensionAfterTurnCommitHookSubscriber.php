<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;

/**
 * Bridges AgentCore after-turn hook dispatch to ExtensionApi hooks.
 *
 * Maps only the already-committed hot batch from AfterTurnCommitHookContext.
 * Never reads EventStore/history for this path.
 */
final readonly class ExtensionAfterTurnCommitHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
        private \Psr\Log\LoggerInterface $logger,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $dto = new AfterTurnCommitHookContextDTO(
            runId: $context->runId,
            turnNo: $context->turnNo,
            status: $context->status,
            events: array_map(
                static function (AfterTurnCommitEventSummary $e): AfterTurnCommitEventSummaryDTO {
                    return new AfterTurnCommitEventSummaryDTO(
                        seq: $e->seq,
                        type: $e->type,
                        payload: $e->payload,
                        turnNo: $e->turnNo,
                        createdAt: $e->createdAt,
                    );
                },
                $context->events,
            ),
            effectsCount: $context->effectsCount,
        );

        foreach ($this->hookRegistry->afterTurnCommitHooks() as $hook) {
            try {
                $hook->onAfterTurnCommit($dto);
            } catch (\Throwable $e) {
                $this->logger->warning('extension.after_turn_commit_hook_failed', [
                    'component' => 'extension_after_turn_hook',
                    'event_type' => 'after_turn_commit_hook_failed',
                    'run_id' => $context->runId,
                    'turn_no' => $context->turnNo,
                    'hook' => $hook::class,
                    // Privacy: hook exceptions may include extension-owned/session content.
                    'exception_class' => $e::class,
                ]);
            }
        }

        return $context;
    }
}
