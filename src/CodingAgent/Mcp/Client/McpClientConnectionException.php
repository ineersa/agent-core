<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

/**
 * Hatfield-owned exception for MCP client connection failures.
 *
 * This wraps vendor SDK connection exceptions so that the Hatfield
 * client boundary never leaks {@see \Mcp\Exception\ConnectionException}
 * or any other vendor type to callers outside {@see McpSdkClientAdapter}.
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
