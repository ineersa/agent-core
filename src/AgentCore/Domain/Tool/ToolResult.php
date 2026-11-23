<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ToolResult
{
    /**
     * Initializes the tool result with call ID, name, content, details, and error status.
     *
     * @param array<int, array<string, mixed>> $content
     * @param array{0: mixed}|null             $programmaticResult in-process only: null means absent, [null] means an explicit null value
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public mixed $details = null,
        public bool $isError = false,
        public ?array $programmaticResult = null,
    ) {
    }
}
