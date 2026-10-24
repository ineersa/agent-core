<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/**
 * CodingAgent chooses background-completion delivery for acknowledged deferred work.
 * AgentCore only freezes the first accepted terminal result and settles after send.
 */
interface DeferredAcknowledgedCompletionRouterInterface
{
    public function deliver(string $deferredId, ToolCallResult $result, bool $explicitContinuation = false): void;

    /**
     * True when deferred work still needs owner attention: pending settlement,
     * parked controller-loss continuation, or an admitted unconsumed receipt.
     */
    public function hasOutstandingAdmission(string $deferredId): bool;

    /** True when an admitted background completion still needs owner consumption. */
    public function hasAdmittedUnconsumedReceipt(string $deferredId): bool;
}
