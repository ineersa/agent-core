<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

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
        // Skip orchestration / mid-tool commits. Capture only on stable turn boundaries.
        if ($this->has($context, 'run_started')
            || $this->has($context, 'tool_execution_start')
        ) {
            return;
        }
        if ($context->effectsCount > 0) {
            return;
        }
        $anchorSeq = $this->resolveCaptureAnchorSeq($context);
        if (null === $anchorSeq) {
            return;
        }
        // Pure command mailbox commits (no stable file boundary in this batch).
        if (($this->has($context, 'agent_command_queued') || $this->has($context, 'agent_command_applied'))
            && !$this->has($context, 'tool_batch_committed')
            && !$this->has($context, 'llm_step_completed')
            && !$this->has($context, 'turn_end')
            && !$this->has($context, 'agent_end')
        ) {
            return;
        }
        $this->service->recordTurnCheckpoint($context->runId, $context->turnNo, $anchorSeq);
    }

    private function resolveCaptureAnchorSeq(AfterTurnCommitHookContextDTO $context): ?int
    {
        foreach ($context->events as $event) {
            if ('turn_end' === $event->type || 'agent_end' === $event->type) {
                return $event->seq;
            }
        }

        // Completed assistant step without in-flight tool work in this commit (post-tool final answer).
        if ($this->has($context, 'llm_step_completed')
            && !$this->has($context, 'tool_execution_start')
            && !$this->has($context, 'tool_call_result_received')
        ) {
            foreach ($context->events as $event) {
                if ('llm_step_completed' === $event->type) {
                    return $event->seq;
                }
            }
        }

        // Post-tool stable file state: tool_batch_committed marks applied tool effects on disk.
        // The same commit batch includes tool_call_result_received / message_end — that is expected.
        if ($this->has($context, 'tool_batch_committed')
            && !$this->has($context, 'llm_step_completed')
            && !$this->has($context, 'turn_end')
            && !$this->has($context, 'agent_end')
            && !$this->has($context, 'tool_execution_start')
        ) {
            foreach ($context->events as $event) {
                if ('tool_batch_committed' === $event->type) {
                    return $event->seq;
                }
            }
        }

        return null;
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
