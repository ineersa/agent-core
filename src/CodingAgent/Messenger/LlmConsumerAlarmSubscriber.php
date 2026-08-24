<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleAlarmEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Temporarily exposes SIGALRM delivery to the LLM Messenger consumer.
 *
 * Console dispatches this event before ConsumeMessagesCommand handles SIGALRM
 * and calls Worker::keepalive(), allowing logs to distinguish signal delivery
 * from a later Worker or transport keepalive failure.
 */
#[AsEventListener(event: ConsoleAlarmEvent::class)]
final readonly class LlmConsumerAlarmSubscriber
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConsoleAlarmEvent $event): void
    {
        if ('messenger:consume' !== $event->getCommand()?->getName()) {
            return;
        }

        $receivers = $event->getInput()->getArgument('receivers');
        if (!\is_array($receivers)) {
            return;
        }

        /** @var list<string> $receiverNames */
        $receiverNames = array_values(array_map(static fn (mixed $receiver): string => (string) $receiver, $receivers));
        if (!\in_array('llm', $receiverNames, true)) {
            return;
        }

        $this->logger->info('LLM consumer SIGALRM tick', [
            'component' => 'messenger',
            'event_type' => 'messenger.keepalive.alarm_tick',
            'receivers' => $receiverNames,
        ]);
    }
}
