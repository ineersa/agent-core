<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use Psr\Log\LoggerInterface;

/**
 * Polls the jbcontext status file and updates TUI status key jbcontext.
 *
 * Self-throttled to ≥250ms. Never requests 100Hz busy ticks.
 */
final class JbcontextStatusPoller
{
    public const string STATUS_KEY = 'jbcontext';
    public const float MIN_POLL_SECONDS = 0.25;

    private float $lastPollAt = 0.0;
    private ?string $lastText = null;

    public function __construct(
        private readonly TuiExtensionContextInterface $tui,
        private readonly JbcontextStatusStore $store,
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
            $text = $this->store->read()->statusText;
            $this->apply($text);
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
