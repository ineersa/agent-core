<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tui;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Interactive-session status poller and one-shot eligibility starter.
 *
 * Starts eligibility only after a real TUI session id is available. Self-throttled
 * to ≥250ms and never requests 100Hz busy ticks.
 */
final class JbcontextStatusPoller
{
    public const string STATUS_KEY = 'jbcontext';
    public const float MIN_POLL_SECONDS = 0.25;

    private float $lastPollAt = 0.0;
    private ?string $lastText = null;
    private ?string $startedSessionId = null;

    public function __construct(
        private readonly ExtensionApiInterface $api,
        private readonly TuiExtensionContextInterface $tui,
        private readonly JbcontextPaths $paths,
        private readonly JbcontextSessionLocator $sessions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function tick(): void
    {
        $now = microtime(true);
        if ($now - $this->lastPollAt < self::MIN_POLL_SECONDS) {
            return;
        }
        $this->lastPollAt = $now;

        try {
            $sessionId = $this->sessions->resolve();
            if (null === $sessionId) {
                $this->apply(null);

                return;
            }

            $store = JbcontextStatusStore::forSession($this->paths, $sessionId);
            $this->ensureEligibilityStarted($store, $sessionId);
            $this->apply($store->read()->statusText);
        } catch (\Throwable) {
            $this->logger->warning('jbcontext.status.poll_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.status.poll_failed',
            ]);
            $this->apply(null);
        }
    }

    private function ensureEligibilityStarted(JbcontextStatusStore $store, string $sessionId): void
    {
        if ($this->startedSessionId === $sessionId) {
            return;
        }
        $this->startedSessionId = $sessionId;

        $shouldDispatch = false;
        $store->update(static function (JbcontextSessionState $current) use ($sessionId, &$shouldDispatch): JbcontextSessionState {
            if ($current->sessionId !== $sessionId) {
                $current = JbcontextSessionState::pending($sessionId);
            }
            if ($current->eligibilityStarted
                || JbcontextSessionModeEnum::Eligible === $current->mode
                || JbcontextSessionModeEnum::Disabled === $current->mode
            ) {
                $shouldDispatch = false;

                return $current;
            }

            $shouldDispatch = true;

            return $current->with(
                statusText: 'jbcontext: checking index…',
                attempt: max(1, $current->attempt),
                eligibilityStarted: true,
            );
        });

        if (!$shouldDispatch) {
            return;
        }

        try {
            $this->api->dispatchExtensionAgentJob(new ExtensionAgentJobRequestDTO(
                handlerId: JbcontextEligibilityJobHandler::HANDLER_ID,
                payload: [
                    'session_id' => $sessionId,
                    'attempt' => 1,
                ],
                jobId: 'jbcontext.eligibility.'.$sessionId.'.attempt.1',
                correlationId: $sessionId,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('jbcontext.eligibility.startup_dispatch_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.eligibility.startup_dispatch_failed',
                'session_id' => $sessionId,
                'exception_class' => $e::class,
            ]);
            $store->write(JbcontextSessionState::pending($sessionId)->with(
                mode: JbcontextSessionModeEnum::Disabled,
                reason: 'jbcontext disabled: could not start background eligibility check.',
                statusText: 'jbcontext disabled: could not start background eligibility check.',
                attempt: 1,
                eligibilityStarted: true,
            ));
        }
    }

    private function apply(?string $text): void
    {
        if ($text === $this->lastText) {
            return;
        }
        $this->lastText = $text;
        $this->tui->setStatus(self::STATUS_KEY, $text);
    }
}
