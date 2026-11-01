<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BashBackgroundRequestService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class BackgroundBashHandler
{
    public function __construct(private BashBackgroundRequestService $requests, private ChildInvocationBackgroundService $children, private MessageBusInterface $commandBus, private DenormalizerInterface $serializer)
    {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        if (\in_array($command->type, ['background_code_mode', 'stop_code_mode'], true)) {
            $this->commandBus->dispatch($this->serializer->denormalize(['run_id' => $command->runId, 'deferred_id' => $command->payload['deferred_id'] ?? '', 'tool_call_id' => $command->payload['tool_call_id'] ?? '', 'stop' => 'stop_code_mode' === $command->type], BackgroundCodeModeInvocationDTO::class));

            return;
        }
        if (!\in_array($command->type, ['background_bash', 'background_child'], true)) {
            return;
        }

        if ('background_child' === $command->type) {
            $accepted = $this->children->request($command->runId ?? '', $command->payload);
        } else {
            $request = $this->requests->prepare($command->runId ?? '', $command->payload);
            $accepted = null !== $request;
            if (null !== $request) {
                // Refusal after authoritative owner classification arrives on
                // the existing runtime event pipe, not a second reply pump.
                $this->commandBus->dispatch($request);
            }
        }
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
