<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Messenger;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Re-arms Messenger's one-shot alarm before later signal work can fail.
 */
#[AsEventListener(event: ConsoleEvents::SIGNAL, priority: self::PRIORITY)]
final class MessengerAlarmPrearmListener
{
    public const int PRIORITY = \PHP_INT_MAX;

    public function __invoke(ConsoleSignalEvent $event): void
    {
        $command = $event->getCommand();
        if (\SIGALRM !== $event->getHandlingSignal() || null === $command || 'messenger:consume' !== $command->getName()) {
            return;
        }

        $application = $command->getApplication();
        $interval = $application?->getAlarmInterval();
        if (null !== $interval) {
            $application->setAlarmInterval($interval);
        }
    }
}
