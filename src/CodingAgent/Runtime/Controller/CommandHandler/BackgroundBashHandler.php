<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeBackgroundRequestService;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BashBackgroundRequestService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class BackgroundBashHandler
{
    public function __construct(private BashBackgroundRequestService $requests, private ChildInvocationBackgroundService $children, private CodeModeBackgroundRequestService $codeMode)
    {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        if (!\in_array($command->type, ['background_bash', 'background_child', 'background_code_mode', 'stop_code_mode'], true)) {
            return;
        }

        $accepted = match ($command->type) {
            'background_child' => $this->children->request($command->runId ?? '', $command->payload),
            'background_code_mode', 'stop_code_mode' => $this->codeMode->request($command->runId ?? '', $command->payload, 'stop_code_mode' === $command->type),
            default => $this->requests->request($command->runId ?? '', $command->payload),
        };
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
