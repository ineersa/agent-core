<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\DTO\StopBashRequestDTO;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class StopBashHandler
{
    public function __construct(private BackgroundProcessManager $processes, private DenormalizerInterface $serializer, private ValidatorInterface $validator)
    {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $command = $event->command;
        if ('stop_bash' !== $command->type) {
            return;
        }
        $request = $this->serializer->denormalize([
            'run_id' => $command->runId ?? '',
            'record_id' => $command->payload['record_id'] ?? 0,
        ], StopBashRequestDTO::class);
        $available = false;
        $stopped = false;
        if (0 === \count($this->validator->validate($request))) {
            $available = $this->processes->isAcceptedRecord($request->recordId, $request->runId);
            if ($available) {
                // The controller retains native supervision after the client returns.
                // The owner commits suppression before granting signal permission.
                $stopped = !$this->processes->stopByRecordId($request->recordId, $request->runId, waitForExit: false)->alreadyFinished;
            }
        }
        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::BackgroundProcessStopCompleted->value,
            runId: $command->runId ?? '',
            seq: 0,
            payload: ['commandId' => $command->id, 'available' => $available, 'stopped' => $stopped],
        ));
    }
}
