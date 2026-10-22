<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BashBackgroundRequestService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class BackgroundBashHandler
{
    public function __construct(private BashBackgroundRequestService $requests, private ChildInvocationBackgroundService $children)
    {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        if (!\in_array($command->type, ['background_bash', 'background_child'], true)) {
            return;
        }

        $accepted = 'background_child' === $command->type
            ? $this->children->request($command->runId ?? '', $command->payload)
            : $this->requests->request($command->runId ?? '', $command->payload);
        if (!$accepted) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $command->runId ?? '',
                seq: 0,
                payload: ['error' => 'The selected invocation is no longer eligible for backgrounding.'],
            ));
        }
    }
}
