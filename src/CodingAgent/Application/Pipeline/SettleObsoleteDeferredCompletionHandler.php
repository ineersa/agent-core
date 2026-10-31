<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Application\Message\DTO\MarkObsoleteDeferredCompletionDTO;
use Ineersa\CodingAgent\Application\Message\DTO\SettleObsoleteDeferredCompletionDTO;

/** Event-free owner settlement for obsolete deferred completions. */
final readonly class SettleObsoleteDeferredCompletionHandler implements RunMessageHandler
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof SettleObsoleteDeferredCompletionDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof SettleObsoleteDeferredCompletionDTO) {
            throw new \InvalidArgumentException('Unsupported obsolete deferred settlement message.');
        }

        $metadata = $this->deferred->findMetadataByDeferredId($message->deferredId);
        if (null === $metadata) {
            return new HandlerResult();
        }
        if ('completed' === $metadata->status) {
            return new HandlerResult();
        }
        if ($metadata->reference->runId !== $message->runId()
            || $metadata->reference->turnNo !== $message->turnNo()
            || $metadata->reference->stepId !== $message->stepId()) {
            throw new \RuntimeException('Obsolete deferred settlement does not match its correlation identity.');
        }

        return new HandlerResult(postCommitActions: [
            new DispatchLocalMetadataMessageDTO(new MarkObsoleteDeferredCompletionDTO(
                $message->deferredId,
                $message->runId(),
                $message->turnNo(),
                $message->stepId(),
            )),
        ]);
    }
}
