<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the bash tool.
 *
 * Configured max timeout remains a runtime check in BashTool (depends on AppConfig).
 */
final class BashArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Shell command executed through bash -c; use shell quoting as needed.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "command" argument is required and must be a non-empty string.')]
        public readonly string $command = '',
        // TODO(commit 2, settings-derived): the provider-visible timeout
        // description "Timeout in seconds (default: %d, max: %d). Use for
        // commands that may hang." depends on BashToolConfig values, so it
        // needs a Symfony SchemaProviderInterface fragment
        // (ai.platform.json_schema.provider) wired into the native Factory/
        // Describer used by RegistryBackedToolbox. Do not hard-code config
        // values into this static attribute.
        #[Assert\Positive(message: 'The "timeout" argument must be a positive integer.')]
        public readonly ?int $timeout = null,
    ) {
    }
}
