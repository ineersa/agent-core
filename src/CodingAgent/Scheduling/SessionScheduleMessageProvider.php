<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Scheduling;

use Ineersa\CodingAgent\Scheduling\DTO\ExecuteSessionScheduleDTO;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\MessageProviderInterface;

final readonly class SessionScheduleMessageProvider implements MessageProviderInterface
{
    public function __construct(private string $runId, private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMessages(MessageContext $context): iterable
    {
        $invocationId = hash('sha256', $this->id.'|'.$context->triggeredAt->format('U.u'));
        yield new RedispatchMessage(new Envelope(
            new ExecuteSessionScheduleDTO($this->runId, $this->id, $invocationId),
            [new BusNameStamp('agent.execution.bus')],
        ), 'tool');
    }
}
