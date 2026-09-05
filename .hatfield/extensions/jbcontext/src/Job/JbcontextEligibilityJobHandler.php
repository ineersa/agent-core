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
 * Interactive-session eligibility / retry / first incremental refresh worker.
 *
 * Never creates a first index. Transient status failures retry under a hard
 * ~30s wall-clock budget that includes CLI status timeouts.
 */
final class JbcontextEligibilityJobHandler implements ExtensionAgentJobHandlerInterface
{
    public const string HANDLER_ID = 'jbcontext.eligibility';

    /** @var callable(int): void */
    private $sleeper;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $packageRoot,
        ?callable $sleeper = null,
        ?callable $clock = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void
    {
        $sessionId = trim((string) ($payload['session_id'] ?? $correlationId ?? ''));
        if ('' === $sessionId) {
            $this->logger->error('jbcontext.eligibility.missing_session', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.missing_session',
                'job_id' => $jobId,
            ]);

            return;
        }

        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $store = JbcontextStatusStore::forSession($paths, $sessionId);
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));
        $now = ($this->clock)();

        $state = $store->read();
        if (JbcontextSessionModeEnum::Disabled === $state->mode
            || JbcontextSessionModeEnum::Eligible === $state->mode
        ) {
            return;
        }

        if (!JbcontextRetrySchedule::canAttemptStatus($state->elapsedSeconds($now))) {
            $this->disable(
                $store,
                'jbcontext disabled: status check budget exhausted. Fix CLI auth/daemon access and restart Hatfield.',
                'budget_exhausted',
                $attempt,
                $sessionId,
            );

            return;
        }

        if (!is_dir($paths->ideaDir)) {
            $this->disable(
                $store,
                'jbcontext disabled: project has no .idea directory. Open the project in JetBrains IDE and run jbcontext index manually before enabling search.',
                'missing_idea',
                $attempt,
                $sessionId,
            );

            return;
        }

        $cli = new JbcontextCli(
            $api->exec(),
            $paths->projectRoot,
            statusTimeoutSeconds: JbcontextRetrySchedule::STATUS_TIMEOUT_SECONDS,
        );
        $status = $cli->status();
        if (!$status['ok'] || null === $status['payload']) {
            $this->handleTransient(
                $api,
                $store,
                $attempt,
                (string) ($status['error'] ?? 'status_failed'),
                $sessionId,
            );

            return;
        }

        if (!JbcontextStatusParser::hasExistingSnapshot($status['payload'])) {
            $this->disable(
                $store,
                'jbcontext disabled: no existing index snapshot. Run `jbcontext index` manually once for this repository, then restart Hatfield.',
                'no_index',
                $attempt,
                $sessionId,
            );

            return;
        }

        $store->write(new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Eligible,
            reason: null,
            statusText: 'jbcontext: indexed',
            attempt: $attempt,
            startedAt: $state->startedAt,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: ($this->clock)(),
        ));

        $this->logger->info('jbcontext.eligibility.ok', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.ok',
            'attempt' => $attempt,
            'session_id' => $sessionId,
            'correlation_id' => $correlationId,
        ]);

        try {
            (new JbcontextAssetInstaller($paths, $this->packageRoot, $this->logger))->install();
        } catch (\Throwable) {
            $this->logger->warning('jbcontext.assets.install_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.install_failed',
                'session_id' => $sessionId,
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
                'session_id' => $sessionId,
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
        string $sessionId,
    ): void {
        $now = ($this->clock)();
        $state = $store->read();
        $sleep = JbcontextRetrySchedule::sleepBeforeNextAttempt($attempt, $state->elapsedSeconds($now));
        if (null === $sleep) {
            $this->disable(
                $store,
                'jbcontext disabled: status check failed after retries. Fix CLI auth/daemon access and restart Hatfield.',
                'status_exhausted:'.$errorCode,
                $attempt,
                $sessionId,
            );

            return;
        }

        $nextAttempt = $attempt + 1;
        $store->write($state->with(
            mode: JbcontextSessionModeEnum::Pending,
            clearReason: true,
            statusText: 'jbcontext: checking index…',
            attempt: $attempt,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: $now,
        ));

        $this->logger->warning('jbcontext.eligibility.retry', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.retry',
            'attempt' => $attempt,
            'next_attempt' => $nextAttempt,
            'delay_seconds' => $sleep,
            'error' => $errorCode,
            'session_id' => $sessionId,
        ]);

        // Bounded wait lives in the background worker so the TUI stays responsive.
        ($this->sleeper)($sleep);

        try {
            $api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: self::HANDLER_ID,
                payload: [
                    'session_id' => $sessionId,
                    'attempt' => $nextAttempt,
                ],
                jobId: 'jbcontext.eligibility.'.$sessionId.'.attempt.'.$nextAttempt,
                correlationId: $sessionId,
            ));
        } catch (\Throwable) {
            $this->logger->error('jbcontext.eligibility.retry_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.retry_dispatch_failed',
                'attempt' => $nextAttempt,
                'session_id' => $sessionId,
            ]);
            $this->disable(
                $store,
                'jbcontext disabled: could not schedule status retry.',
                'retry_dispatch_failed',
                $attempt,
                $sessionId,
            );
        }
    }

    private function disable(
        JbcontextStatusStore $store,
        string $statusText,
        string $reason,
        int $attempt,
        string $sessionId,
    ): void {
        $current = $store->read();
        $store->write(new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: $statusText,
            statusText: $statusText,
            attempt: $attempt,
            startedAt: $current->startedAt,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: ($this->clock)(),
        ));

        $this->logger->warning('jbcontext.eligibility.disabled', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.disabled',
            'reason' => $reason,
            'attempt' => $attempt,
            'session_id' => $sessionId,
        ]);
    }
}
