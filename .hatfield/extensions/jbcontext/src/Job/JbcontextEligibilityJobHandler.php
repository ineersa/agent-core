<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Job;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\Jbcontext\Assets\JbcontextAssetInstaller;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextStatusParser;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Startup eligibility / retry / first incremental refresh worker.
 *
 * Never creates a first index. Transient status failures retry on a fixed
 * schedule totaling ~30s; exhaustion disables search for the session.
 */
final class JbcontextEligibilityJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string HANDLER_ID = 'jbcontext.eligibility';

    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $packageRoot,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $store = new JbcontextStatusStore($paths->statusPath);
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));

        $state = $store->read();
        if (JbcontextSessionModeEnum::Disabled === $state->mode
            || JbcontextSessionModeEnum::Eligible === $state->mode
        ) {
            return;
        }

        if (!is_dir($paths->ideaDir)) {
            $this->disable(
                $store,
                'jbcontext disabled: project has no .idea directory. Open the project in JetBrains IDE and run jbcontext index manually before enabling search.',
                'missing_idea',
                $attempt,
                $correlationId,
            );

            return;
        }

        $cli = new JbcontextCli($api->exec(), $paths->projectRoot);
        $status = $cli->status();
        if (!$status['ok'] || null === $status['payload']) {
            $this->handleTransient($api, $store, $attempt, (string) ($status['error'] ?? 'status_failed'), $correlationId);

            return;
        }

        if (!JbcontextStatusParser::hasExistingSnapshot($status['payload'])) {
            $this->disable(
                $store,
                'jbcontext disabled: no existing index snapshot. Run `jbcontext index` manually once for this repository, then restart Hatfield.',
                'no_index',
                $attempt,
                $correlationId,
            );

            return;
        }

        $store->write(new JbcontextSessionState(
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: indexed',
            attempt: $attempt,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        ));

        $this->logger->info('jbcontext.eligibility.ok', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.ok',
            'attempt' => $attempt,
            'correlation_id' => $correlationId,
        ]);

        try {
            (new JbcontextAssetInstaller($paths, $this->packageRoot, $this->logger))->install();
        } catch (\Throwable) {
            $this->logger->warning('jbcontext.assets.install_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.install_failed',
                'correlation_id' => $correlationId,
            ]);
        }

        $index = $cli->indexSilent();
        if (!$index['ok']) {
            $this->logger->warning('jbcontext.index.startup_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.index.startup_failed',
                'error' => $index['error'],
                'exit_code' => $index['exit_code'],
                'correlation_id' => $correlationId,
            ]);
            // Eligibility already succeeded; incremental refresh failure does not disable search.
            $store->update(static fn (JbcontextSessionState $s): JbcontextSessionState => $s->with(
                statusText: 'jbcontext: indexed (refresh failed)',
            ));

            return;
        }

        $store->update(static fn (JbcontextSessionState $s): JbcontextSessionState => $s->with(
            statusText: 'jbcontext: indexed',
        ));
    }

    private function handleTransient(
        ExtensionApiInterface $api,
        JbcontextStatusStore $store,
        int $attempt,
        string $errorCode,
        ?string $correlationId,
    ): void {
        $delay = JbcontextRetrySchedule::delayAfterAttempt($attempt);
        if (null === $delay || $attempt >= JbcontextRetrySchedule::maxAttempts()) {
            $this->disable(
                $store,
                'jbcontext disabled: status check failed after retries. Fix CLI auth/daemon access and restart Hatfield.',
                'status_exhausted:'.$errorCode,
                $attempt,
                $correlationId,
            );

            return;
        }

        $nextAttempt = $attempt + 1;
        $nextAt = microtime(true) + $delay;
        $store->write(new JbcontextSessionState(
            mode: JbcontextSessionModeEnum::Pending,
            reason: null,
            statusText: 'jbcontext: checking index…',
            attempt: $attempt,
            nextRetryAt: $nextAt,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        ));

        $this->logger->warning('jbcontext.eligibility.retry', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.retry',
            'attempt' => $attempt,
            'next_attempt' => $nextAttempt,
            'delay_seconds' => $delay,
            'error' => $errorCode,
            'correlation_id' => $correlationId,
        ]);

        // Bounded wait lives in the background worker so the TUI stays responsive.
        ($this->sleeper)($delay);

        try {
            $api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: self::HANDLER_ID,
                payload: ['attempt' => $nextAttempt],
                jobId: 'jbcontext.eligibility.attempt.'.$nextAttempt,
                correlationId: $correlationId,
            ));
        } catch (\Throwable) {
            $this->logger->error('jbcontext.eligibility.retry_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.retry_dispatch_failed',
                'attempt' => $nextAttempt,
                'correlation_id' => $correlationId,
            ]);
            $this->disable(
                $store,
                'jbcontext disabled: could not schedule status retry.',
                'retry_dispatch_failed',
                $attempt,
                $correlationId,
            );
        }
    }

    private function disable(
        JbcontextStatusStore $store,
        string $statusText,
        string $reason,
        int $attempt,
        ?string $correlationId,
    ): void {
        $store->write(new JbcontextSessionState(
            mode: JbcontextSessionModeEnum::Disabled,
            reason: $statusText,
            statusText: $statusText,
            attempt: $attempt,
            nextRetryAt: null,
            reindexPending: false,
            reindexRunning: false,
            updatedAt: microtime(true),
        ));

        $this->logger->warning('jbcontext.eligibility.disabled', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.disabled',
            'reason' => $reason,
            'attempt' => $attempt,
            'correlation_id' => $correlationId,
        ]);
    }
}
