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
 * ~30s wall-clock budget that includes CLI status timeouts. Jobs must carry a
 * positive check_generation so stale workers from a previous controller attempt
 * cannot overwrite a newer pending/eligible/disabled claim. Missing or invalid
 * generations fail closed.
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

        $checkGeneration = $this->requireCheckGeneration($payload, $sessionId, $jobId);
        if (null === $checkGeneration) {
            return;
        }

        $paths = JbcontextPaths::fromProjectRoot($api->getCwd());
        $store = JbcontextStatusStore::forSession($paths, $sessionId);
        $attempt = max(1, (int) ($payload['attempt'] ?? 1));
        $now = ($this->clock)();
        $state = $store->read();

        if (!$this->isCurrentGeneration($state, $checkGeneration)) {
            $this->logger->info('jbcontext.eligibility.stale_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.stale_generation',
                'session_id' => $sessionId,
                'job_generation' => $checkGeneration,
                'current_generation' => $state->checkGeneration,
            ]);

            return;
        }

        if (JbcontextSessionModeEnum::Pending !== $state->mode) {
            return;
        }

        if (!JbcontextRetrySchedule::canAttemptStatus($state->elapsedSeconds($now))) {
            $this->disable(
                $store,
                'jbcontext disabled: status check budget exhausted. Fix jbcontext CLI access and restart Hatfield.',
                'budget_exhausted',
                $attempt,
                $sessionId,
                $checkGeneration,
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
                $checkGeneration,
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
            $errorCode = (string) ($status['error'] ?? 'status_failed');
            $this->handleTransient(
                $api,
                $store,
                $attempt,
                $errorCode,
                $sessionId,
                $checkGeneration,
                $status['detail'] ?? null,
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
                $checkGeneration,
            );

            return;
        }

        $becameEligible = false;
        $store->update(function (JbcontextSessionState $current) use (
            $attempt,
            $checkGeneration,
            &$becameEligible,
        ): JbcontextSessionState {
            if (!$this->isCurrentGeneration($current, $checkGeneration)
                || JbcontextSessionModeEnum::Pending !== $current->mode
            ) {
                $becameEligible = false;

                return $current;
            }

            $becameEligible = true;

            return $current->with(
                mode: JbcontextSessionModeEnum::Eligible,
                clearReason: true,
                statusText: 'jbcontext: indexed',
                attempt: $attempt,
                reindexPending: false,
                reindexRunning: false,
                eligibilityStarted: true,
                updatedAt: ($this->clock)(),
            );
        });

        if (!$becameEligible) {
            return;
        }

        $this->logger->info('jbcontext.eligibility.ok', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.ok',
            'attempt' => $attempt,
            'session_id' => $sessionId,
            'check_generation' => $checkGeneration,
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
            $store->update(function (JbcontextSessionState $s) use ($checkGeneration): JbcontextSessionState {
                if (!$this->isCurrentGeneration($s, $checkGeneration)
                    || JbcontextSessionModeEnum::Eligible !== $s->mode
                ) {
                    return $s;
                }

                return $s->with(statusText: 'jbcontext: indexed (refresh failed)');
            });

            return;
        }

        $store->update(function (JbcontextSessionState $s) use ($checkGeneration): JbcontextSessionState {
            if (!$this->isCurrentGeneration($s, $checkGeneration)
                || JbcontextSessionModeEnum::Eligible !== $s->mode
            ) {
                return $s;
            }

            return $s->with(statusText: 'jbcontext: indexed');
        });
    }

    private function handleTransient(
        ExtensionApiInterface $api,
        JbcontextStatusStore $store,
        int $attempt,
        string $errorCode,
        string $sessionId,
        int $checkGeneration,
        ?string $detail = null,
    ): void {
        $now = ($this->clock)();
        $state = $store->read();
        if (!$this->isCurrentGeneration($state, $checkGeneration)
            || JbcontextSessionModeEnum::Pending !== $state->mode
        ) {
            return;
        }

        $sleep = JbcontextRetrySchedule::sleepBeforeNextAttempt($attempt, $state->elapsedSeconds($now));
        if (null === $sleep) {
            $statusText = null !== $detail && '' !== $detail
                ? 'jbcontext disabled: '.$detail
                : 'jbcontext disabled: status check failed after retries. Fix jbcontext CLI access and restart Hatfield.';
            $this->disable(
                $store,
                $statusText,
                'status_exhausted:'.$errorCode,
                $attempt,
                $sessionId,
                $checkGeneration,
            );

            return;
        }

        $nextAttempt = $attempt + 1;
        $keptPending = false;
        $store->update(function (JbcontextSessionState $current) use (
            $attempt,
            $checkGeneration,
            $now,
            &$keptPending,
        ): JbcontextSessionState {
            if (!$this->isCurrentGeneration($current, $checkGeneration)
                || JbcontextSessionModeEnum::Pending !== $current->mode
            ) {
                $keptPending = false;

                return $current;
            }

            $keptPending = true;

            return $current->with(
                mode: JbcontextSessionModeEnum::Pending,
                clearReason: true,
                statusText: 'jbcontext: checking index…',
                attempt: $attempt,
                reindexPending: false,
                reindexRunning: false,
                eligibilityStarted: true,
                updatedAt: $now,
            );
        });

        if (!$keptPending) {
            return;
        }

        $this->logger->warning('jbcontext.eligibility.retry', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.retry',
            'attempt' => $attempt,
            'next_attempt' => $nextAttempt,
            'delay_seconds' => $sleep,
            'error' => $errorCode,
            'session_id' => $sessionId,
            'check_generation' => $checkGeneration,
        ]);

        // Bounded wait lives in the background worker so the TUI stays responsive.
        ($this->sleeper)($sleep);

        $latest = $store->read();
        if (!$this->isCurrentGeneration($latest, $checkGeneration)
            || JbcontextSessionModeEnum::Pending !== $latest->mode
        ) {
            return;
        }

        try {
            $api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: self::HANDLER_ID,
                payload: [
                    'session_id' => $sessionId,
                    'attempt' => $nextAttempt,
                    'check_generation' => $checkGeneration,
                ],
                jobId: 'jbcontext.eligibility.'.$sessionId.'.g'.$checkGeneration.'.attempt.'.$nextAttempt,
                correlationId: $sessionId,
            ));
        } catch (\Throwable) {
            $this->logger->error('jbcontext.eligibility.retry_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.retry_dispatch_failed',
                'attempt' => $nextAttempt,
                'session_id' => $sessionId,
                'check_generation' => $checkGeneration,
            ]);
            $this->disable(
                $store,
                'jbcontext disabled: could not schedule status retry.',
                'retry_dispatch_failed',
                $attempt,
                $sessionId,
                $checkGeneration,
            );
        }
    }

    private function disable(
        JbcontextStatusStore $store,
        string $statusText,
        string $reason,
        int $attempt,
        string $sessionId,
        int $checkGeneration,
    ): void {
        $wrote = false;
        $store->update(function (JbcontextSessionState $current) use (
            $statusText,
            $attempt,
            $checkGeneration,
            &$wrote,
        ): JbcontextSessionState {
            if (!$this->isCurrentGeneration($current, $checkGeneration)
                || JbcontextSessionModeEnum::Pending !== $current->mode
            ) {
                $wrote = false;

                return $current;
            }

            $wrote = true;

            return $current->with(
                mode: JbcontextSessionModeEnum::Disabled,
                reason: $statusText,
                statusText: $statusText,
                attempt: $attempt,
                reindexPending: false,
                reindexRunning: false,
                eligibilityStarted: true,
                updatedAt: ($this->clock)(),
            );
        });

        if (!$wrote) {
            return;
        }

        $this->logger->warning('jbcontext.eligibility.disabled', [
            'component' => 'jbcontext',
            'event_type' => 'jbcontext.eligibility.disabled',
            'reason' => $reason,
            'attempt' => $attempt,
            'session_id' => $sessionId,
            'check_generation' => $checkGeneration,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireCheckGeneration(array $payload, string $sessionId, ?string $jobId): ?int
    {
        if (!\array_key_exists('check_generation', $payload)) {
            $this->logger->error('jbcontext.eligibility.missing_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.missing_generation',
                'session_id' => $sessionId,
                'job_id' => $jobId,
            ]);

            return null;
        }

        $raw = $payload['check_generation'];
        if (!\is_int($raw) && !(\is_string($raw) && is_numeric($raw))) {
            $this->logger->error('jbcontext.eligibility.invalid_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.invalid_generation',
                'session_id' => $sessionId,
                'job_id' => $jobId,
            ]);

            return null;
        }

        $checkGeneration = (int) $raw;
        if ($checkGeneration < 1) {
            $this->logger->error('jbcontext.eligibility.invalid_generation', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.invalid_generation',
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
