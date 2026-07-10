<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Raised when the tail of an events.jsonl file cannot yield a valid integer seq.
 */
final class EventLogLastSequenceException extends \RuntimeException
{
}
