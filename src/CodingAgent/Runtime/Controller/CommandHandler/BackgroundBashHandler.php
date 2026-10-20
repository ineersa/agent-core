<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BashBackgroundRequestService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class BackgroundBashHandler
{
    public function __construct(private BashBackgroundRequestService $requests)
    {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        if ('background_bash' !== $command->type) {
            return;
        }

        if (!$this->requests->request($command->runId ?? '', $command->payload)) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $command->runId ?? '',
                seq: 0,
                payload: ['error' => 'The selected Bash invocation is no longer eligible for backgrounding.'],
            ));
        }
    }
}
