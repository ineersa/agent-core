<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

/**
 * Thrown when CAS retry attempts are exhausted in RunMessageProcessor.
 *
 * This exception ensures the message is properly rejected back to the
 * transport (e.g., retry or dead-letter queue) rather than silently
 * dropped. The message will be retried by the transport depending on
 * its retry policy configuration.
 */
final class CasRetryExhaustedException extends \RuntimeException
{
}
