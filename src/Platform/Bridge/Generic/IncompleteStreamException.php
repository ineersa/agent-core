<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

use Symfony\AI\Platform\Exception\RuntimeException;

/**
 * Thrown when a completions stream produced chunks but ended
 * before emitting a finish reason, indicating a truncated or
 * incomplete stream response.
 */
final class IncompleteStreamException extends RuntimeException
{
}
