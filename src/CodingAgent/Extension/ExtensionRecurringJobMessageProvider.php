<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\Hatfield\ExtensionApi\Scheduler\RecurringJobDTO;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\MessageProviderInterface;

final readonly class ExtensionRecurringJobMessageProvider implements MessageProviderInterface
{
    public function __construct(private string $sourceId, private RecurringJobDTO $job, private string $runId)
    {
    }

    public function getId(): string
    {
        return $this->sourceId.':'.$this->job->id;
    }

    public function getMessages(MessageContext $context): iterable
    {
        $id = hash('sha256', $this->getId().'|'.$context->triggeredAt->format('U.u'));
        yield new RedispatchMessage(new Envelope(
            new ExtensionAgentJobMessage($this->job->handlerId, $this->job->payload, $id, $this->runId),
            [new BusNameStamp('agent.execution.bus')],
        ), 'tool');
    }
}
