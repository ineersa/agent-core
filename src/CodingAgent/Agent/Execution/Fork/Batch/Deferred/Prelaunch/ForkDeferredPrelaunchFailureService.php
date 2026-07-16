<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService;

final readonly class ForkDeferredPrelaunchFailureService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private ForkSessionCopyService $sessionCopyService,
    ) {
    }

    public function failDeferredForkTool(
        string $lifecycleId,
        string $parentRunId,
        string $parentToolCallId,
        int $projectionVersion,
        string $forkLocalRunId,
        string $reason,
        ?\Throwable $previous = null,
    ): void {
        $this->batchRepository->applyForkPrelaunchPhase(
            $parentRunId,
            $parentToolCallId,
            ForkDeferredPrelaunchPhaseEnum::Failed,
        );
        $this->batchRepository->markFailed($parentRunId, $parentToolCallId);

        $message = $previous instanceof \Throwable
            ? $previous->getMessage()
            : 'Fork pre-launch failed: '.$reason;

        $this->completionDispatcher->dispatchCompletion(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentToolCallId: $parentToolCallId,
            expectedProjectionVersion: $projectionVersion,
            presentation: $message,
            isError: true,
            errorEnvelope: [
                'error' => ['message' => $message, 'reason' => $reason],
                'details' => ['reason' => $reason],
            ],
        );

        $this->sessionCopyService->removeForkLocalSession($forkLocalRunId);
    }
}
