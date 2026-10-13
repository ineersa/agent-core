<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapDescriptorDTO;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class BootstrapAppliedHandler
{
    public function __construct(private SessionBootstrapDelivery $delivery, private \Psr\Log\LoggerInterface $logger)
    {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if (!\in_array($event->command->type, ['bootstrap.applied', 'bootstrap.cancel'], true)) {
            return;
        }
        try {
            if ('bootstrap.applied' === $event->command->type) {
                $descriptor = SessionBootstrapDescriptorDTO::fromArray($event->command->payload);
                if ($descriptor->runId !== $event->command->runId) {
                    throw new \InvalidArgumentException('Bootstrap acknowledgement run does not match its cut.');
                }
                $this->delivery->acknowledge($descriptor);
            } else {
                $this->delivery->cancelForRun($event->command->runId ?? '');
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('session.bootstrap.command_rejected', ['run_id' => $event->command->runId ?? '',
                'session_id' => $event->command->runId ?? '', 'component' => 'session_bootstrap',
                'event_type' => 'session.bootstrap.command_rejected', 'exception_class' => $exception::class]);
            $event->emit(new \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent(
                \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum::CommandRejected->value, $event->command->runId ?? '', 0,
                ['commandId' => $event->command->id, 'commandType' => $event->command->type, 'status' => 'rejected', 'reason' => 'Bootstrap command does not match an active complete transfer.']));
        }
    }
}
