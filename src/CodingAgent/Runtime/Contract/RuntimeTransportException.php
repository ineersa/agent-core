<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Fatal transport-level failure on the runtime process/pipe/stdin/stdout
 * boundary (controller process died, pipe closed, stream write failed).
 *
 * The TUI runtime poller classifies exactly this type as immediately
 * fatal; domain, EventStore, mapper, and malformed-event failures are
 * deliberately NOT this type so they remain retryable/degradable.
 */
final class RuntimeTransportException extends \RuntimeException
{
}
