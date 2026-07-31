<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tui;

use Doctrine\DBAL\Connection;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmActivityStatusText;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;

/**
 * Polls om_current_activity at most once per Symfony TUI idle interval (250ms)
 * and updates status key om-background for the current session only.
 *
 * Tick callback must never force 100Hz busy ticks (Bridge always returns null).
 */
final class OmBackgroundStatusPoller
{
    public const string STATUS_KEY = 'om-background';
    public const float MIN_POLL_SECONDS = 0.25;

    private ?Connection $connection = null;
    private float $lastPollAt = 0.0;
    private ?string $lastText = null;

    public function __construct(
        private readonly TuiExtensionContextInterface $tui,
        private readonly string $databasePath,
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
            $runId = trim($this->tui->getSessionId());
            if ('' === $runId) {
                $this->apply(null);

                return;
            }

            $row = (new ActivityRepository($this->connection()))->findFresh($runId);
            if (null === $row) {
                $this->apply(null);

                return;
            }

            $this->apply(OmActivityStatusText::format(
                $row['stage'],
                $row['current_tokens'],
                $row['target_tokens'],
            ));
        } catch (\Throwable) {
            $this->logger->warning('om.activity.poll_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.activity.poll_failed',
            ]);
            $this->apply(null);
            $this->connection = null;
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

    private function connection(): Connection
    {
        return $this->connection ??= OmDatabaseFactory::connectAndMigrate($this->databasePath, $this->logger);
    }
}
