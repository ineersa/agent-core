<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Read-only interactive status poller for jbcontext session state.
 *
 * Eligibility starts from the controller session-start hook. Self-throttled to
 * ≥250ms and never requests 100Hz busy ticks.
 *
 * Active work (Pending eligibility, Eligible + reindexRunning) stays visible.
 * Settled outcomes (Disabled failures, Eligible idle indexed/refresh-failed)
 * stay visible for a finite dwell, then clear from the status panel. Mode /
 * generation / status-text changes and leaving active work reset the dwell.
 * Failure reasons remain in session state for on-demand `code_search`.
 */
final class JbcontextStatusPoller
{
    public const string STATUS_KEY = 'jbcontext';
    public const float MIN_POLL_SECONDS = 0.25;
    public const float FOOTER_DWELL_SECONDS = 5.0;

    /** @var callable(): float */
    private $clock;

    private float $lastPollAt = 0.0;
    private ?string $lastText = null;
    private ?string $announcedSettledKey = null;
    private float $announcedSettledAt = 0.0;

    public function __construct(
        private readonly TuiExtensionContextInterface $tui,
        private readonly JbcontextPaths $paths,
        private readonly JbcontextSessionLocator $sessions,
        private readonly LoggerInterface $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function tick(): void
    {
        $now = ($this->clock)();
        if ($now - $this->lastPollAt < self::MIN_POLL_SECONDS) {
            return;
        }
        $this->lastPollAt = $now;

        try {
            $sessionId = $this->sessions->resolve();
            if (null === $sessionId) {
                $this->announcedSettledKey = null;
                $this->apply(null);

                return;
            }

            $store = JbcontextStatusStore::forSession($this->paths, $sessionId);
            if (!is_file($store->path())) {
                $this->announcedSettledKey = null;
                $this->apply(null);

                return;
            }

            $state = $store->read();
            if ($this->isActiveWork($state)) {
                $this->announcedSettledKey = null;
                $this->apply($state->statusText);

                return;
            }

            if ($this->isSettledOutcome($state)) {
                $settledKey = $sessionId
                    .'#'.$state->checkGeneration
                    .'#'.$state->mode->value
                    .'#'.(string) $state->statusText;
                if ($this->announcedSettledKey !== $settledKey) {
                    $this->announcedSettledKey = $settledKey;
                    $this->announcedSettledAt = $now;
                    $this->apply($state->statusText);

                    return;
                }

                if ($now - $this->announcedSettledAt < self::FOOTER_DWELL_SECONDS) {
                    $this->apply($state->statusText);

                    return;
                }

                $this->apply(null);

                return;
            }

            $this->announcedSettledKey = null;
            $this->apply(null);
        } catch (\Throwable) {
            $this->logger->warning('jbcontext.status.poll_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.status.poll_failed',
            ]);
            $this->announcedSettledKey = null;
            $this->apply(null);
        }
    }

    private function isActiveWork(JbcontextSessionState $state): bool
    {
        if (JbcontextSessionModeEnum::Pending === $state->mode) {
            return true;
        }

        return JbcontextSessionModeEnum::Eligible === $state->mode && $state->reindexRunning;
    }

    private function isSettledOutcome(JbcontextSessionState $state): bool
    {
        if (JbcontextSessionModeEnum::Disabled === $state->mode) {
            return true;
        }

        return JbcontextSessionModeEnum::Eligible === $state->mode && !$state->reindexRunning;
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
