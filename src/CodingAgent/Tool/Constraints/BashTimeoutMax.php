<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects a bash `timeout` argument above the configured BashToolConfig max.
 *
 * The bound is settings-derived (tools.bash.max_timeout_seconds), so it
 * cannot be a static Assert option; the validator autowires the same config
 * object the provider-visible schema fragment comes from
 * ({@see \Ineersa\CodingAgent\Tool\Schema\BashTimeoutSchemaProvider}), so the
 * schema `maximum` and the runtime limit can never drift.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BashTimeoutMax extends Constraint
{
    public string $message = 'Timeout must not exceed {{ limit }} seconds ({{ value }} provided).';
}
