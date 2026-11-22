<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\CodingAgent\Agent\Tool\AgentSteerTool;
use Ineersa\CodingAgent\Agent\Tool\NotifyParentTool;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\CompleteSubmissionDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class SubmissionCompletionHandler
{
    public function __construct(private DeferredToolCompletionRepositoryInterface $deferred, private CompleteDeferredToolCallHandler $completion, private SubmissionReceiptRepository $receipts)
    {
    }

    public function __invoke(CompleteSubmissionDTO $message): void
    {
        $identity = $message->completion->deferredId;
        $metadata = $this->deferred->findMetadataByDeferredId($identity);
        if (null === $metadata || $metadata->reference->runId !== $message->runId()
            || !\in_array($metadata->reference->toolName, [AgentSteerTool::NAME, NotifyParentTool::NAME], true)) {
            throw new \RuntimeException('Agent submission reply differs from its native source.');
        }
        if (DeferredToolCompletionStatusEnum::Completed === $metadata->status) {
            return;
        }
        $source = $this->deferred->findByDeferredId($identity);
        if (null === $source || $source->runId !== $message->runId()
            || !\in_array($source->toolName, [AgentSteerTool::NAME, NotifyParentTool::NAME], true)) {
            throw new \RuntimeException('Agent submission reply differs from its native source.');
        }
        if (!$message->completion->isError && null === $this->receipts->receipt($source->runId, $identity)) {
            throw new \RuntimeException('Agent submission success requires canonical source acceptance.');
        }
        ($this->completion)($message->completion);
    }
}
