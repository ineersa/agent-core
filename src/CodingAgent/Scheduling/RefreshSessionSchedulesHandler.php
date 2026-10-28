<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Scheduling;

use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

final class RefreshSessionSchedulesHandler
{
    /** @var array<string, string> definition id => framework recurring-message id */
    private array $registered = [];

    public function __construct(
        private readonly SessionScheduleRepository $schedules,
        private readonly BackgroundProcessCompletionPoller $ownership,
        #[Autowire(service: 'scheduler.provider.default')]
        private readonly ScheduleProviderInterface $provider,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private readonly string $sessionId,
    ) {
    }

    /** Reconcile shared definitions; Symfony computes and dispatches all due occurrences. */
    #[AsPeriodicTask('1 second')]
    public function __invoke(): void
    {
        if ('' === $this->sessionId) {
            return;
        }
        $current = [];
        foreach ($this->ownership->resolveOwnedSessionIds($this->sessionId) as $runId) {
            foreach ($this->schedules->all($runId) as $definition) {
                $message = $this->schedules->recurring($definition);
                $current[$definition->id] = $message->getId();
                if (!isset($this->registered[$definition->id])) {
                    $this->provider->getSchedule()->add($message);
                }
            }
        }
        foreach (array_diff_key($this->registered, $current) as $messageId) {
            $this->provider->getSchedule()->removeById($messageId);
        }
        $this->registered = $current;
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class)]
    #[AsEventListener(event: ControllerSessionShutdownEvent::class)]
    public function clear(ControllerSessionStartingEvent|ControllerSessionShutdownEvent $event): void
    {
        if ($event instanceof ControllerSessionStartingEvent) {
            $this->schedules->activate($event->sessionId);
        } else {
            $this->schedules->deactivate($event->sessionId);
        }
        foreach ($this->ownership->resolveOwnedSessionIds($event->sessionId) as $runId) {
            $this->schedules->clear($runId);
        }
    }
}
