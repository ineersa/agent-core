<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\CodingAgent\Config\FileRewindConfig;

/**
 * Captures file checkpoints at user-command and assistant-turn boundaries (after commit).
 */
final class FileRewindCheckpointHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private readonly FileRewindCheckpointService $checkpointService,
        private readonly FileRewindConfig $config,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        if (!$this->config->enabled || !$this->checkpointService->isOperational()) {
            return $context;
        }

        foreach ($context->events as $summary) {
            if (RunEventTypeEnum::AgentCommandApplied->value === $summary->type) {
                $this->checkpointService->recordCheckpoint(
                    $context->runId,
                    $context->turnNo,
                    FileRewindCheckpointKindEnum::UserBoundary,
                    $summary->seq,
                );
                break;
            }
            if (RunEventTypeEnum::TurnEnd->value === $summary->type
                || RunEventTypeEnum::AgentEnd->value === $summary->type) {
                $this->checkpointService->recordCheckpoint(
                    $context->runId,
                    $context->turnNo,
                    FileRewindCheckpointKindEnum::AssistantBoundary,
                    $summary->seq,
                );
                break;
            }
        }

        return $context;
    }
}
