<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionLocator;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Read-only interactive status poller for jbcontext session state.
 *
 * Eligibility starts from the controller session-start hook. Self-throttled to
 * ≥250ms and never requests 100Hz busy ticks.
 */
final class JbcontextStatusPoller
{
    public const string STATUS_KEY = 'jbcontext';
    public const float MIN_POLL_SECONDS = 0.25;

    private float $lastPollAt = 0.0;
    private ?string $lastText = null;

    public function __construct(
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
            if (!is_file($store->path())) {
                $this->apply(null);

                return;
            }

            $this->apply($store->read()->statusText);
        } catch (\Throwable) {
            $this->logger->warning('jbcontext.status.poll_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.status.poll_failed',
            ]);
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
