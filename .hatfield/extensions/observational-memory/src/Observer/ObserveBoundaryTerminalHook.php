<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Observer;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Psr\Log\LoggerInterface;

/**
 * Hot-path terminal detector: dispatches a scalar extension-agent job only.
 *
 * Never reads history, never opens OM SQLite, never invokes the model.
 */
final readonly class ObserveBoundaryTerminalHook implements AfterTurnCommitHookInterface
{
    public const string HANDLER_ID = 'observational_memory.observe_boundary';

    public function __construct(
        private ExtensionApiInterface $api,
        private OmSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
    {
        if (!$this->settings->enabled) {
            return;
        }

        $terminal = $this->detectTerminal($context);
        if (null === $terminal) {
            return;
        }

        $jobId = hash('sha256', implode('|', [
            $context->runId,
            (string) $terminal['end_seq'],
            $terminal['status'],
            $this->settings->rendererVersion,
            $this->settings->observerSchemaVersion,
        ]));

        $request = new ExtensionAgentJobRequestDTO(
            handlerId: self::HANDLER_ID,
            payload: [
                'run_id' => $context->runId,
                'terminal_end_seq' => $terminal['end_seq'],
                'terminal_status' => $terminal['status'],
                'renderer_version' => $this->settings->rendererVersion,
                'observer_schema_version' => $this->settings->observerSchemaVersion,
            ],
            jobId: $jobId,
            correlationId: $context->runId,
        );

        try {
            $this->api->dispatchExtensionAgentJob($request);
        } catch (\Throwable $e) {
            // Best-effort: never fail the committed turn.
            $this->logger->error('om.observe.dispatch_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.observe.dispatch_failed',
                'run_id' => $context->runId,
                'job_id' => $jobId,
                'exception_class' => $e::class,
            ]);
        }
    }

    /**
     * @return array{end_seq: int, status: string}|null
     */
    private function detectTerminal(AfterTurnCommitHookContextDTO $context): ?array
    {
        $events = $context->events;
        if ([] === $events) {
            return null;
        }

        foreach ($events as $event) {
            if (!$event instanceof AfterTurnCommitEventSummaryDTO) {
                continue;
            }
            if ('agent_end' === $event->type) {
                $reason = (string) ($event->payload['reason'] ?? '');
                if (\in_array($reason, ['completed', 'cancelled'], true)) {
                    return ['end_seq' => $event->seq, 'status' => $reason];
                }
            }
        }

        // Final non-retryable / exhausted failure: batch status failed and no retry path.
        if ('failed' === $context->status) {
            foreach ($events as $event) {
                if ('llm_step_failed' !== $event->type) {
                    continue;
                }
                $retryable = (bool) ($event->payload['retryable'] ?? false);
                $retriesExhausted = (bool) ($event->payload['retries_exhausted'] ?? false);
                if (!$retryable || $retriesExhausted) {
                    return ['end_seq' => $event->seq, 'status' => 'failed'];
                }
            }
        }

        return null;
    }
}
