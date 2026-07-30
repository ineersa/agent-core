<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

/**
 * Reads durable child-run extension allowlists for worker/runtime filtering.
 *
 * Implementations typically source this from RunStarted metadata. Return values:
 *  - null: parent/global run (no child filter)
 *  - list: effective extension class allowlist for a child run (may be empty)
 */
interface ChildRunExtensionAllowlistReaderInterface
{
    /**
     * @return list<string>|null
     */
    public function readAllowedExtensions(string $runId): ?array;
}
