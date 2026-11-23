<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * Hatfield-owned exception for MCP client connection failures.
 *
 * The adapter raises this while connecting and keeps the SDK exception as
 * the cause. Connection failures during an established tool call are mapped
 * to {@see McpClientInvocationException} instead.
 */
final class McpClientConnectionException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
