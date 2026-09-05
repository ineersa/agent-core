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
 * Never indexes when the session is not eligible.
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
        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $store = new JbcontextStatusStore($paths->statusPath);

        $state = $store->update(static function (JbcontextSessionState $current): JbcontextSessionState {
            if (JbcontextSessionModeEnum::Eligible !== $current->mode) {
                return $current->with(reindexPending: false, reindexRunning: false);
            }
            if ($current->reindexRunning) {
                return $current;
            }
            if (!$current->reindexPending) {
                return $current;
            }

            return $current->with(
                statusText: 'jbcontext: refreshing index…',
                reindexPending: false,
                reindexRunning: true,
            );
        });

        if (JbcontextSessionModeEnum::Eligible !== $state->mode || !$state->reindexRunning) {
            return;
        }

        $cli = new JbcontextCli($api->exec(), $paths->projectRoot);
        $result = $cli->indexSilent();

        $store->update(static function (JbcontextSessionState $current) use ($result): JbcontextSessionState {
            if (JbcontextSessionModeEnum::Eligible !== $current->mode) {
                return $current->with(reindexPending: false, reindexRunning: false);
            }

            $stillPending = $current->reindexPending;
            if ($stillPending) {
                // Another completed turn arrived while this job ran; leave pending
                // so a subsequent coalesced job can drain it.
                return $current->with(
                    statusText: $result['ok'] ? 'jbcontext: indexed' : 'jbcontext: indexed (refresh failed)',
                    reindexRunning: false,
                );
            }

            return $current->with(
                statusText: $result['ok'] ? 'jbcontext: indexed' : 'jbcontext: indexed (refresh failed)',
                reindexPending: false,
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
                'correlation_id' => $correlationId,
            ]);

            return;
        }

        $this->logger->info('jbcontext.index.reindex_ok', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.index.reindex_ok',
            'job_id' => $jobId,
            'correlation_id' => $correlationId,
        ]);
    }
}
