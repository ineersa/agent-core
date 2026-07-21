<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;

/**
 * Bridges AgentCore after-turn hook dispatch to ExtensionApi hooks.
 *
 * Maps the just-persisted batch into full public SessionEventDTO values
 * (payload, turnNo, createdAt) without rereading events.jsonl.
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
                static function ($e) use ($context): SessionEventDTO {
                    return new SessionEventDTO(
                        runId: $context->runId,
                        seq: $e->seq,
                        turnNo: $e->turnNo > 0 ? $e->turnNo : $context->turnNo,
                        type: $e->type,
                        payload: $e->payload,
                        createdAt: null !== $e->createdAt
                            ? new \DateTimeImmutable($e->createdAt)
                            : new \DateTimeImmutable(),
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
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }

        return $context;
    }
}
