<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;

/**
 * Prepared owner acceptance of one true terminal tool outcome.
 *
 * Detached acceptance settles the deferred lifecycle without collecting into the
 * original caller batch. Attached acceptance accompanies ordinary batch collect.
 */
final readonly class PreparedToolTerminalAcceptanceDTO
{
    public function __construct(
        public bool $accepted,
        public bool $duplicate,
        public bool $detached,
        public DeferredToolCompletionCorrelation $correlation,
        public string $completionId,
        public string $resultFingerprint,
        public ?RecordToolInvocationLifecycleDTO $lifecycleAction = null,
        public ?string $rejectionReason = null,
    ) {
        if ($this->accepted && null === $this->lifecycleAction && !$this->duplicate) {
            throw new \LogicException('Accepted first terminal acceptance requires a lifecycle projection.');
        }
        if (!$this->accepted && null === $this->rejectionReason) {
            throw new \LogicException('Rejected terminal acceptance requires a reason.');
        }
    }

    public static function rejected(
        DeferredToolCompletionCorrelation $correlation,
        string $completionId,
        string $resultFingerprint,
        string $reason,
    ): self {
        return new self(
            accepted: false,
            duplicate: false,
            detached: ToolInvocationCallerDispositionEnum::BackgroundAcknowledged === $correlation->callerDisposition,
            correlation: $correlation,
            completionId: $completionId,
            resultFingerprint: $resultFingerprint,
            rejectionReason: $reason,
        );
    }
}
