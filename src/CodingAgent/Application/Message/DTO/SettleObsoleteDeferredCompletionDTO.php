<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Owner entry for metadata-only settlement of an obsolete deferred completion.
 *
 * Carries no conversational result. The captured metadata action marks the
 * pending correlation completed under the verified owner transition.
 */
final readonly class SettleObsoleteDeferredCompletionDTO extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $deferredId,
    ) {
        parent::__construct($runId, $turnNo, $stepId, 1, 'settle-obsolete-deferred-'.$deferredId);
    }
}
