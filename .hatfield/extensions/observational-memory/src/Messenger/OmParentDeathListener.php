<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Stops the package Messenger worker when the exact owning controller PID dies.
 *
 * Parent PID is supplied by the extension supervisor via OM_PARENT_PID.
 * Does not scan process tables for unrelated workers.
 */
final class OmParentDeathListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $parentPid,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->parentPid <= 0) {
            return;
        }

        if ($this->isProcessAlive($this->parentPid)) {
            return;
        }

        $this->logger->warning('om.worker.parent_dead', [
            'component' => 'observational_memory',
            'event_type' => 'om.worker.parent_dead',
            'parent_pid' => $this->parentPid,
        ]);

        $event->getWorker()->stop();
    }

    private function isProcessAlive(int $pid): bool
    {
        // Linux: /proc/<pid> is authoritative for the exact PID.
        if (is_dir('/proc/'.$pid)) {
            return true;
        }

        // Fallback for environments without /proc: signal 0 probes existence.
        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // Without a reliable probe, leave the worker running rather than
        // risk stopping a still-owned consumer.
        return true;
    }
}
