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
    public function __construct(
        #[Schema(description: 'PHP script source executed as a function body. Call tool(name, arguments) for registered tools including MCP names. Use toon_encode/toon_decode when needed. Use return for the final value.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "script" argument is required and must be a non-empty string.')]
        public readonly string $script = '',
    ) {
    }
}
