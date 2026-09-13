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
    public const int DEFAULT_TIMEOUT_SECONDS = 60;
    public const int MAX_TIMEOUT_SECONDS = 300;
    public const int DEFAULT_MEMORY_LIMIT_MB = 256;
    public const int MAX_MEMORY_LIMIT_MB = 1024;

    public function __construct(
        #[Schema(description: 'PHP script source executed as a function body. Call tool(name, arguments) for registered tools including MCP names. Use toon_encode/toon_decode when needed. Use return for the final value.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "script" argument is required and must be a non-empty string.')]
        public readonly string $script = '',
        #[Schema(
            description: 'Script wall-clock budget in seconds (default '.self::DEFAULT_TIMEOUT_SECONDS.', max '.self::MAX_TIMEOUT_SECONDS.'). The remaining parent tool budget wins when smaller. Nested tool calls receive the remaining budget cooperatively.',
            minimum: 1,
            maximum: self::MAX_TIMEOUT_SECONDS,
        )]
        #[Assert\Range(
            min: 1,
            max: self::MAX_TIMEOUT_SECONDS,
            notInRangeMessage: 'The "timeout_seconds" argument must be an integer between {{ min }} and {{ max }}.',
        )]
        public readonly int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS,
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
    ) {
    }
}
