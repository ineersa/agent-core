<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Removes user-accepted background process state for the controller session.
 *
 * Shutdown handles the ordinary end of controller ownership. Starting repairs
 * rows and exact sidecars left behind when a prior controller crashed.
 */
final class BackgroundProcessControllerSessionLifecycleListener
{
    public function __construct(
        private readonly BackgroundProcessCompletionPoller $completionPoller,
        private readonly ProcessStore $store,
        private readonly BackgroundProcessManager $manager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class)]
    public function onSessionStarting(ControllerSessionStartingEvent $event): void
    {
        $this->cleanup($event->sessionId, 'starting');
    }

    #[AsEventListener(event: ControllerSessionShutdownEvent::class)]
    public function onSessionShutdown(ControllerSessionShutdownEvent $event): void
    {
        $this->cleanup($event->sessionId, 'shutdown');
    }

    private function cleanup(string $controllerSessionId, string $phase): void
    {
        $cleaned = 0;
        $failed = 0;
        $scopeCount = 0;

        try {
            $sessionIds = $this->completionPoller->resolveOwnedSessionIds($controllerSessionId);
        } catch (\Throwable $exception) {
            ++$failed;
            $sessionIds = [$controllerSessionId];
            $this->logger->warning('background_process.session_cleanup_child_scope_failed', [
                'component' => 'tool.background_process',
                'event_type' => 'background_process.session_cleanup_child_scope_failed',
                'lifecycle_phase' => $phase,
                'exception_class' => $exception::class,
            ]);
        }

        foreach ($sessionIds as $sessionId) {
            ++$scopeCount;
            try {
                foreach ($this->store->sessionSnapshots($sessionId) as $entity) {
                    try {
                        if (null === $entity->finishedAt) {
                            $hasLaunchEvidence = null !== $entity->pid
                                || null !== $entity->startedAt
                                || null !== $this->manager->readPidEvidence($entity->statusPath)
                                || null !== $this->manager->readStatusEvidence($entity->statusPath);
                            if (!$hasLaunchEvidence) {
                                // After launcher quiescence, unused prepared rows
                                // never launched and can be retired without signalling.
                                $this->store->deleteUnusedPreparedRecord($entity->id, $sessionId);
                                ++$cleaned;

                                continue;
                            }
                            $this->manager->stopByRecordId($entity->id, $sessionId);
                        }

                        if (!$this->manager->cleanupFinishedRecord($entity->id, $sessionId)) {
                            ++$failed;
                            $this->logger->warning('background_process.session_cleanup_row_failed', [
                                'component' => 'tool.background_process',
                                'event_type' => 'background_process.session_cleanup_row_failed',
                                'lifecycle_phase' => $phase,
                                'scope_kind' => $sessionId === $controllerSessionId ? 'parent' : 'child',
                                'failure_kind' => 'sidecar_validation',
                            ]);

                            continue;
                        }

                        ++$cleaned;
                    } catch (\Throwable $exception) {
                        ++$failed;
                        $this->logger->warning('background_process.session_cleanup_row_failed', [
                            'component' => 'tool.background_process',
                            'event_type' => 'background_process.session_cleanup_row_failed',
                            'lifecycle_phase' => $phase,
                            'scope_kind' => $sessionId === $controllerSessionId ? 'parent' : 'child',
                            'failure_kind' => 'operation_exception',
                            'exception_class' => $exception::class,
                        ]);
                    }
                }
            } catch (\Throwable $exception) {
                ++$failed;
                $this->logger->warning('background_process.session_cleanup_scope_failed', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.session_cleanup_scope_failed',
                    'lifecycle_phase' => $phase,
                    'scope_kind' => $sessionId === $controllerSessionId ? 'parent' : 'child',
                    'exception_class' => $exception::class,
                ]);
            }
        }

        $this->logger->info('background_process.session_cleanup_completed', [
            'component' => 'tool.background_process',
            'event_type' => 'background_process.session_cleanup_completed',
            'lifecycle_phase' => $phase,
            'scope_count' => $scopeCount,
            'cleaned_count' => $cleaned,
            'failed_count' => $failed,
        ]);
    }
}
