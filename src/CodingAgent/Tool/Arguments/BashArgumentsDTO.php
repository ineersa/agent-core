<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Ineersa\CodingAgent\Tool\Schema\BashTimeoutSchemaProvider;
use Ineersa\CodingAgent\Tool\Validation\BashTimeout\BashTimeoutMax;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the bash tool.
 *
 * The configured max timeout and the provider-visible description are
 * settings-derived (BashToolConfig); BashTimeoutMax validates the runtime
 * bound and BashTimeoutSchemaProvider feeds the schema fragment from the
 * same config instance.
 */
final class BashArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Shell command executed through bash -c; use shell quoting as needed.')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'The "command" argument is required and must be a non-empty string.')]
        public readonly string $command = '',
        #[Schema(provider: BashTimeoutSchemaProvider::class)]
        #[Assert\Positive(message: 'The "timeout" argument must be a positive integer.')]
        #[BashTimeoutMax]
        public readonly ?int $timeout = null,
    ) {
    }
}
