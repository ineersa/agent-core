<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[AsEventListener]
final readonly class CodeModeProcessedResultSubscriber
{
    public function __construct(private CodeModeExecutionRepository $executions, private CodeModeResultArtifactStore $artifacts, private RunLockManager $locks, private SerializerInterface $serializer, private MessageBusInterface $commandBus, private CodeModeExecutionService $execution)
    {
    }

    public function __invoke(ProcessedToolResultDTO $event): void
    {
        $result = $event->result;
        $row = $this->executions->invocation($result->runId(), $result->toolCallId);
        if (null === $row) {
            return;
        }
        $call = $this->execution->invocation($row['call_reference']);
        if (!$this->execution->sameInvocation($call, $event->invocation) || !$this->execution->matches($result, $call)) {
            throw new \RuntimeException('Code-mode terminal result does not match its frozen invocation.');
        }
        $this->locks->synchronized($result->runId(), function () use ($result, $row): void {
            $current = $this->executions->invocation($result->runId(), $result->toolCallId);
            if (null === $current || $current['identity'] !== $row['identity']) {
                throw new \RuntimeException('Code-mode owner changed before terminal publication.');
            }
            if (null !== $current['result_reference']) {
                // First accepted terminal evidence is immutable across redelivery.
                return;
            }
            $reference = $this->artifacts->publish($result);
            $this->executions->freezeResult($row['identity'], $this->serializer->serialize($reference, 'json'));
        });
        $event->handled = true;
        $this->commandBus->dispatch(new CodeModeOwnerDTO($result->runId(), $row['identity'], $result->toolCallId));
    }
}
