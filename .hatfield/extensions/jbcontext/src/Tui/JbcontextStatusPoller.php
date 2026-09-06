<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Read-only interactive status poller for jbcontext session state.
 *
 * Eligibility starts from the controller session-start hook. Self-throttled to
 * ≥250ms and never requests 100Hz busy ticks.
 *
 * Disabled terminal failures stay visible for a finite dwell, then clear from
 * the status panel. Generation/mode changes reset the dwell. The failure
 * reason remains in session state for on-demand `code_search` explanation.
 */
final class JbcontextStatusPoller
{
    public const string STATUS_KEY = 'jbcontext';
    public const float MIN_POLL_SECONDS = 0.25;
    public const float DISABLED_FOOTER_DWELL_SECONDS = 5.0;

    /** @var callable(): float */
    private $clock;

    private float $lastPollAt = 0.0;
    private ?string $lastText = null;
    private ?string $announcedDisabledKey = null;
    private float $announcedDisabledAt = 0.0;

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
                $this->announcedDisabledKey = null;
                $this->apply(null);

                return;
            }

            $store = JbcontextStatusStore::forSession($this->paths, $sessionId);
            if (!is_file($store->path())) {
                $this->announcedDisabledKey = null;
                $this->apply(null);

                return;
            }

            $state = $store->read();
            if (JbcontextSessionModeEnum::Disabled === $state->mode) {
                $disabledKey = $sessionId.'#'.$state->checkGeneration.'#'.(string) $state->reason;
                if ($this->announcedDisabledKey !== $disabledKey) {
                    $this->announcedDisabledKey = $disabledKey;
                    $this->announcedDisabledAt = $now;
                    $this->apply($state->statusText);

                    return;
                }

                if ($now - $this->announcedDisabledAt < self::DISABLED_FOOTER_DWELL_SECONDS) {
                    $this->apply($state->statusText);

                    return;
                }

                $this->apply(null);

                return;
            }

            $this->announcedDisabledKey = null;
            $this->apply($state->statusText);
        } catch (\Throwable) {
            $this->logger->warning('jbcontext.status.poll_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.status.poll_failed',
            ]);
            $this->announcedDisabledKey = null;
            $this->apply(null);
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
