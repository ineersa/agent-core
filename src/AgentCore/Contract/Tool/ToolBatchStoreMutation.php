<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

/**
 * Result of an atomic {@see ToolBatchStoreInterface::mutate()} callback.
 */
final readonly class ToolBatchStoreMutation
{
    public function __construct(
        public mixed $returnValue,
        public ?ToolBatchStateDTO $nextState = null,
    ) {
    }
}
