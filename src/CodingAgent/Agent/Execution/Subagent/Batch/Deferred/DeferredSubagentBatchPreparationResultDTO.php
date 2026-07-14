<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;

/**
 * Result of preparing still-pending children for ordered runtime start.
 */
final readonly class DeferredSubagentBatchPreparationResultDTO
{
    /**
     * @param list<ChildRunIdentityDTO>      $identities
     * @param list<PreparedAgentChildRunDTO> $preparedChildren
     */
    public function __construct(
        public array $identities,
        public array $preparedChildren,
    ) {
    }
}
