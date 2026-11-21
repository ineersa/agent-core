<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Arguments for the code_mode tool.
 */
final class CodeModeArgumentsDTO
{
    public const int DEFAULT_TIMEOUT_SECONDS = 1800;
    public const int DEFAULT_MEMORY_LIMIT_MB = 256;
    public const int MAX_MEMORY_LIMIT_MB = 1024;

    public function __construct(
        #[Schema(description: 'PHP script source executed as a function body. Call tool(name, arguments) for registered tools including MCP names. Use toon_encode/toon_decode when needed. Use return for the final value.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "script" argument is required and must be a non-empty string.')]
        public readonly string $script,
        #[Schema(
            description: 'Script wall-clock budget in seconds (default '.self::DEFAULT_TIMEOUT_SECONDS.'). Before background acceptance the remaining parent tool budget wins when smaller. After acceptance only an explicit timeout applies, measured from launch. Nested tool calls observe the live script deadline through cooperative cancellation, not a fixed per-call timeout.',
            minimum: 1,
        )]
        #[Assert\Range(
            min: 1,
            minMessage: 'The "timeout_seconds" argument must be a positive integer.',
        )]
        public readonly ?int $timeout_seconds = null,
        #[Schema(
            description: 'PHP memory_limit for the script subprocess in mebibytes (default '.self::DEFAULT_MEMORY_LIMIT_MB.', max '.self::MAX_MEMORY_LIMIT_MB.').',
            minimum: 1,
            maximum: self::MAX_MEMORY_LIMIT_MB,
        )]
        #[Assert\Range(
            min: 1,
            max: self::MAX_MEMORY_LIMIT_MB,
            notInRangeMessage: 'The "memory_limit_mb" argument must be an integer between {{ min }} and {{ max }}.',
        )]
        public readonly int $memory_limit_mb = self::DEFAULT_MEMORY_LIMIT_MB,
        #[Schema(description: 'Run in the background so you can continue other work without waiting for completion. Default false.')]
        public readonly bool $backgrounded = false,
    ) {
    }
}
