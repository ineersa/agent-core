<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Job;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Psr\Log\LoggerInterface;

/**
 * Hot-path completed-turn detector: marks reindex pending and dispatches one job.
 *
 * Never runs jbcontext CLI work inline. Cancelled/failed turns are ignored.
 */
final readonly class JbcontextCompletedTurnHook implements AfterTurnCommitHookInterface
{
    public function __construct(
        private ExtensionApiInterface $api,
        private JbcontextPaths $paths,
        private JbcontextSessionLocator $sessions,
        private LoggerInterface $logger,
    ) {
    }

    public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
    {
        if (!$this->isCompletedTurn($context)) {
            return;
        }

        $store = $this->sessions->storeFor($this->paths, $context->runId);
        if (null === $store) {
            return;
        }

        $state = $store->read();
        if (JbcontextSessionModeEnum::Eligible !== $state->mode) {
            return;
        }

        $store->update(static function (JbcontextSessionState $current): JbcontextSessionState {
            if (JbcontextSessionModeEnum::Eligible !== $current->mode) {
                return $current;
            }

            return $current->with(reindexPending: true);
        });

        // Deterministic job id coalesces duplicate completed-turn dispatches while
        // a prior reindex message is still queued for the same run.
        $jobId = 'jbcontext.reindex.'.$context->runId;
        try {
            $this->api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: JbcontextReindexJobHandler::HANDLER_ID,
                payload: [
                    'session_id' => $context->runId,
                    'run_id' => $context->runId,
                    'turn_no' => $context->turnNo,
                ],
                jobId: $jobId,
                correlationId: $context->runId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('jbcontext.reindex.dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.reindex.dispatch_failed',
                'run_id' => $context->runId,
                'job_id' => $jobId,
                'exception_class' => $e::class,
            ]);
        }
    }

    private function isCompletedTurn(AfterTurnCommitHookContextDTO $context): bool
    {
        foreach ($context->events as $event) {
            if (!$event instanceof AfterTurnCommitEventSummaryDTO) {
                continue;
            }
            if ('agent_end' !== $event->type) {
                continue;
            }
            $reason = (string) ($event->payload['reason'] ?? '');
            if ('completed' === $reason) {
                return true;
            }
        }

        return false;
    }
}
