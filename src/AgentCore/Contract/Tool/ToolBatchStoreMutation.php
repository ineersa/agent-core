<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Result of an atomic {@see ToolBatchStoreInterface::mutate()} callback.
 */
final readonly class ToolBatchStoreMutation
{
    /**
     * @param mixed                     $returnValue         Value returned from {@see ToolBatchStoreInterface::mutate()}
     * @param array<string, mixed>|null $nextSerializedState When non-null, persisted as the new batch JSON state
     */
    public function __construct(
        public mixed $returnValue,
        public ?array $nextSerializedState = null,
    ) {
    }
}
