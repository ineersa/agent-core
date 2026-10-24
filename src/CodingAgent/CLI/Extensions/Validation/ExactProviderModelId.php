<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * Exact provider/model id (provider/model), matching OM runtime requirements.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ExactProviderModelId extends Constraint
{
    public string $message = 'observational_memory.model must be an exact provider/model id (example: llama_cpp/flash).';
}
