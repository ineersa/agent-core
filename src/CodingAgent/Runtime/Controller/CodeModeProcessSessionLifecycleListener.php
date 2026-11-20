<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class CodeModeProcessSessionLifecycleListener
{
    public function __construct(
        private BackgroundProcessCompletionPoller $scopes,
        private CodeModeProcessOwner $processes,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class)]
    #[AsEventListener(event: ControllerSessionShutdownEvent::class)]
    public function cleanup(ControllerSessionStartingEvent|ControllerSessionShutdownEvent $event): void
    {
        try {
            $scopes = $this->scopes->resolveOwnedSessionIds($event->sessionId);
        } catch (\Throwable $exception) {
            $this->logFailure($event->sessionId, $event->sessionId, $exception);
            $scopes = [$event->sessionId];
        }
        foreach ($scopes as $runId) {
            try {
                $this->processes->cleanup($runId);
            } catch (\Throwable $exception) {
                $this->logFailure($event->sessionId, $runId, $exception);
            }
        }
    }

    private function logFailure(string $sessionId, string $runId, \Throwable $exception): void
    {
        $this->logger->warning('code_mode.session_cleanup_failed', [
            'session_id' => $sessionId,
            'run_id' => $runId,
            'component' => 'tool.code_mode',
            'event_type' => 'code_mode.session_cleanup_failed',
            'error_type' => $exception::class,
        ]);
    }
}
