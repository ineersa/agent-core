<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredAcknowledgedCompletionRouterInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/** Route acknowledged deferred terminals through existing background-completion admission. */
final readonly class DeferredAcknowledgedCompletionRouter implements DeferredAcknowledgedCompletionRouterInterface
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private BackgroundCompletionReceiptRepository $receipts,
    ) {
    }

    public function deliver(string $deferredId, ToolCallResult $result, bool $explicitContinuation = false): void
    {
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId)
            ?? throw new \RuntimeException('Acknowledged deferred completion lost its registration metadata.');
        $reference = $metadata->reference;
        if ($reference->runId !== $result->runId()
            || $reference->toolCallId !== $result->toolCallId
            || $reference->turnNo !== $result->turnNo()
            || $reference->stepId !== $result->stepId()
            || $reference->attempt !== $result->attempt()
            || $reference->idempotencyKey !== $result->idempotencyKey()) {
            throw new \RuntimeException('Acknowledged deferred completion does not match its frozen invocation.');
        }
        $origin = 'code_mode' === $reference->toolName
            ? BackgroundCompletionOriginEnum::CodeMode
            : BackgroundCompletionOriginEnum::DeferredChild;
        $text = implode("\n", array_column($result->result['content'] ?? [], 'text'));
        if (BackgroundCompletionOriginEnum::CodeMode === $origin) {
            $text = \sprintf(
                "Code-mode result (run: %s, tool call: %s, invocation: %s):\n%s",
                $reference->runId,
                $reference->toolCallId,
                $deferredId,
                $text,
            );
        }

        $this->commandBus->dispatch(new DeliverBackgroundCompletionDTO(
            $result->runId(),
            $deferredId,
            $text,
            $origin,
            deferredId: $deferredId,
            invocation: $reference,
            explicitContinuation: $explicitContinuation,
        ));
    }

    public function hasOutstandingAdmission(string $deferredId): bool
    {
        $metadata = $this->deferred->findMetadataByDeferredId($deferredId);
        if (null === $metadata) {
            return false;
        }
        if ('pending' === $metadata->status) {
            return true;
        }
        $reference = $metadata->reference;
        if ($this->deferred->requiresContinuation($reference->runId, $reference->toolCallId)) {
            return true;
        }

        return $this->hasAdmittedUnconsumedReceipt($deferredId);
    }

    public function hasAdmittedUnconsumedReceipt(string $deferredId): bool
    {
        $receipt = $this->receipts->receipt($deferredId);

        return null !== $receipt && \in_array($receipt->disposition, [
            BackgroundCompletionDispositionEnum::Pending,
            BackgroundCompletionDispositionEnum::Forwarding,
        ], true);
    }
}
