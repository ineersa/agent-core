<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBackgroundAcknowledgement;

/**
 * Prepared wait-resolution package for one background acknowledgment.
 *
 * The batch delta and lifecycle projection must commit together under one
 * verified transition. Native execution status remains pending.
 */
final readonly class PreparedToolBackgroundAcknowledgement
{
    /**
     * @param list<ToolCallResult>  $orderedResults
     * @param list<ExecuteToolCall> $effectsToDispatch
     * @param list<object>          $localActions
     */
    public function __construct(
        public bool $accepted,
        public bool $duplicate,
        public bool $complete,
        public ?ToolCallResult $acknowledgementResult = null,
        public ?ToolBackgroundAcknowledgement $acknowledgement = null,
        public array $orderedResults = [],
        public array $effectsToDispatch = [],
        public array $localActions = [],
        public ?FinalizeToolBatchDTO $batchAction = null,
        public ?RecordToolInvocationLifecycleDTO $lifecycleAction = null,
        public ?string $rejectionReason = null,
    ) {
        if ($this->accepted && null === $this->acknowledgementResult) {
            throw new \LogicException('Accepted background acknowledgment requires a result envelope.');
        }
        if ($this->accepted && null === $this->lifecycleAction && !$this->duplicate) {
            throw new \LogicException('Accepted first background acknowledgment requires a lifecycle projection.');
        }
        if (!$this->accepted && null === $this->rejectionReason) {
            throw new \LogicException('Rejected background acknowledgment requires a reason.');
        }
    }

    public static function rejected(string $reason): self
    {
        return new self(
            accepted: false,
            duplicate: false,
            complete: false,
            rejectionReason: $reason,
        );
    }
}
