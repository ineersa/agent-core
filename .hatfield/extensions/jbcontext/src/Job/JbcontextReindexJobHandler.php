<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Job;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Incremental silent reindex after completed assistant turns.
 *
 * Coalesces via status flags: pending work set by the hot hook is drained here.
 * Never indexes when the session is not eligible. Jobs must carry the check
 * generation they observed so a stale in-flight completion cannot clear newer
 * running flags or overwrite status after a controller restart reclaim.
 */
final readonly class JbcontextReindexJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string HANDLER_ID = 'jbcontext.reindex';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $sessionId = trim((string) ($payload['session_id'] ?? $payload['run_id'] ?? $correlationId ?? ''));
        if ('' === $sessionId) {
            $this->logger->error('jbcontext.reindex.missing_session', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.reindex.missing_session',
                'job_id' => $jobId,
            ]);

            return;
        }

        $checkGeneration = $this->requireCheckGeneration($payload, $sessionId, $jobId);
        if (null === $checkGeneration) {
            return;
        }

        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $store = JbcontextStatusStore::forSession($paths, $sessionId);

        $claimed = false;
        $store->update(function (JbcontextSessionState $current) use (
            $checkGeneration,
            &$claimed,
        ): JbcontextSessionState {
            if (!$this->isCurrentGeneration($current, $checkGeneration)) {
                $claimed = false;

                return $current;
            }
            if (JbcontextSessionModeEnum::Eligible !== $current->mode) {
                $claimed = false;

                return $current->with(reindexPending: false, reindexRunning: false);
            }
            if ($current->reindexRunning) {
                // Another job owns the CLI work. Keep pending for a later drain.
                $claimed = false;

                return $current->with(reindexPending: true);
            }
            if (!$current->reindexPending) {
                $claimed = false;

                return $current;
            }

            $claimed = true;

            return $current->with(
                statusText: 'jbcontext: refreshing index…',
                reindexPending: false,
                reindexRunning: true,
            );
        });

        if (!$claimed) {
            return;
        }

        $cli = new JbcontextCli($api->exec(), $paths->projectRoot);
        $result = $cli->indexSilent();

        $store->update(function (JbcontextSessionState $current) use (
            $result,
            $checkGeneration,
        ): JbcontextSessionState {
            if (!$this->isCurrentGeneration($current, $checkGeneration)) {
                return $current;
            }
            if (JbcontextSessionModeEnum::Eligible !== $current->mode) {
                return $current->with(reindexPending: false, reindexRunning: false);
            }

            return $current->with(
                statusText: $result['ok'] ? 'jbcontext: indexed' : 'jbcontext: indexed (refresh failed)',
                reindexRunning: false,
            );
        });

        if (!$result['ok']) {
            $this->logger->warning('jbcontext.index.reindex_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.index.reindex_failed',
                'error' => $result['error'],
                'exit_code' => $result['exit_code'],
                'job_id' => $jobId,
                'session_id' => $sessionId,
                'check_generation' => $checkGeneration,
                'correlation_id' => $correlationId,
            ]);

            return;
        }

        $this->logger->info('jbcontext.index.reindex_ok', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.index.reindex_ok',
            'job_id' => $jobId,
            'session_id' => $sessionId,
            'check_generation' => $checkGeneration,
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireCheckGeneration(array $payload, string $sessionId, ?string $jobId): ?int
    {
        if (!\array_key_exists('check_generation', $payload)) {
            $this->logger->error('jbcontext.reindex.missing_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.reindex.missing_generation',
                'session_id' => $sessionId,
                'job_id' => $jobId,
            ]);

            return null;
        }

        $raw = $payload['check_generation'];
        if (!\is_int($raw) && !(\is_string($raw) && is_numeric($raw))) {
            $this->logger->error('jbcontext.reindex.invalid_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.reindex.invalid_generation',
                'session_id' => $sessionId,
                'job_id' => $jobId,
            ]);

            return null;
        }

        $checkGeneration = (int) $raw;
        if ($checkGeneration < 1) {
            $this->logger->error('jbcontext.reindex.invalid_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.reindex.invalid_generation',
                'session_id' => $sessionId,
                'job_id' => $jobId,
                'job_generation' => $checkGeneration,
            ]);

            return null;
        }

        return $checkGeneration;
    }

    private function isCurrentGeneration(JbcontextSessionState $state, int $checkGeneration): bool
    {
        return $state->checkGeneration === $checkGeneration;
    }
}
