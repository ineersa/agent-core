<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;

final readonly class FileRewindAfterTurnCommitHook implements AfterTurnCommitHookInterface
{
    public function __construct(
        private FileRewindService $service,
        private FileRewindConfig $config,
    ) {
    }

    public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
    {
        if (!$this->config->enabled || !$this->service->isOperational()) {
            return;
        }
        // v1: skip tool-effect commits so checkpoints align with plain assistant turns (pre-edit restore target).
        if ($this->has($context, 'run_started')
            || $this->has($context, 'tool_execution_start')
            || $this->has($context, 'tool_batch_committed')
            || $this->has($context, 'agent_command_queued')
            || $this->has($context, 'agent_command_applied')
        ) {
            return;
        }
        if ($context->effectsCount > 0) {
            return;
        }
        $anchorSeq = 0;
        $capture = false;
        foreach ($context->events as $event) {
            if ('turn_end' === $event->type || 'agent_end' === $event->type) {
                $anchorSeq = $event->seq;
                $capture = true;
                break;
            }
        }
        if (!$capture) {
            return;
        }
        $this->service->recordTurnCheckpoint($context->runId, $context->turnNo, $anchorSeq);
    }

    private function has(AfterTurnCommitHookContextDTO $context, string $type): bool
    {
        foreach ($context->events as $event) {
            if ($type === $event->type) {
                return true;
            }
        }

        return false;
    }
}
