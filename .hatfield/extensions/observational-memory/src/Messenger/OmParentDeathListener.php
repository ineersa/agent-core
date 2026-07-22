<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Stops the OM Worker when the owning HeadlessController process no longer exists.
 *
 * Watches only the exact parent PID passed via HATFIELD_OM_PARENT_PID.
 * Never scans/kills unrelated processes and never signals root-owned workers.
 */
final class OmParentDeathListener implements EventSubscriberInterface
{
    private bool $stopped = false;

    public function __construct(
        private readonly int $parentPid,
        private readonly LoggerInterface $logger,
    ) {
        if ($this->parentPid <= 1) {
            throw new \InvalidArgumentException('OmParentDeathListener parentPid must be > 1.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if ($this->stopped) {
            return;
        }

        if ($this->isParentAlive($this->parentPid)) {
            return;
        }

        $this->stopped = true;
        $this->logger->warning('om.worker.parent_dead', [
            'component' => 'observational_memory',
            'event_type' => 'om.worker.parent_dead',
            'parent_pid' => $this->parentPid,
        ]);
        $event->getWorker()->stop();
    }

    private function isParentAlive(int $pid): bool
    {
        // Never treat init/root process 1 as a living owner.
        if ($pid <= 1) {
            return false;
        }

        // Prefer /proc on Linux: is_dir is non-signalling and does not require
        // permission to signal the process (posix_kill(0) can fail for euid mismatch).
        if (is_dir('/proc')) {
            return is_dir('/proc/'.$pid);
        }

        if (!\function_exists('posix_kill')) {
            // Without either /proc or posix we cannot detect parent death; leave
            // the worker running until external TERM/SIGINT.
            return true;
        }

        return @posix_kill($pid, 0);
    }
}
