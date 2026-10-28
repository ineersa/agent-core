<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

final readonly class ToolInvocationRequestDTO
{
    /** @param array<string,mixed> $arguments */
    public function __construct(public string $runId, public string $invocationId, public string $toolName, public array $arguments, public bool $retainOutput = false)
    {
    }
}
