<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Mcp\Client as SdkClient;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\TransportInterface;

/**
 * Creates MCP client adapters from typed server definitions.
 *
 * This factory isolates SDK class imports to the Mcp\Client namespace.
 * It constructs the appropriate transport (STDIO or HTTP) and builds
 * a ready-to-connect {@see McpSdkClientAdapter} wrapping the SDK.
 *
 * This factory is NOT called during normal app boot in Phase 0.
 * It is ready for Phase 1 when the broker starts connecting to servers.
 */
final class McpSdkClientFactory
{
    /**
     * Client name reported during MCP initialization.
     * Phase 1+ can wire the real app/package version from runtime configuration.
     */
    private const CLIENT_NAME = 'hatfield';

    /**
     * Client version reported during MCP initialization.
     * Phase 1+ can wire the real app/package version from runtime configuration.
     */
    private const CLIENT_VERSION = '0.1.0';

    /**
     * Create a client adapter for the given server definition.
     *
     * The returned adapter is NOT connected — callers must invoke
     * {@see McpClientInterface::connect()} when ready.
     *
     * @param McpServerDefinitionDTO $server Resolved server definition with interpolated values
     */
    public function create(McpServerDefinitionDTO $server): McpSdkClientAdapter
    {
        $transport = $this->createTransport($server);
        $client = $this->createSdkClient($server);

        return new McpSdkClientAdapter($client, $transport);
    }

    private function createTransport(McpServerDefinitionDTO $server): TransportInterface
    {
        if (null === $server->transportType) {
            throw new \RuntimeException(\sprintf('MCP server "%s": cannot create transport — no transport type resolved.', $server->name));
        }

        if (McpTransportTypeEnum::STDIO === $server->transportType) {
            return new StdioTransport(
                command: $server->command ?? throw new \RuntimeException('STDIO transport requires a command.'),
                args: $server->args,
                cwd: $server->cwd,
                env: [] !== $server->env ? $server->env : null,
            );
        }

        // HTTP transport
        return new HttpTransport(
            endpoint: $server->url ?? throw new \RuntimeException('HTTP transport requires a URL.'),
            headers: $server->headers,
        );
    }

    private function createSdkClient(McpServerDefinitionDTO $server): SdkClient
    {
        // Use SDK builder's setClientInfo() which internally creates Implementation and ClientCapabilities objects.
        // Passing the server's startupTimeoutMs/timeoutMs for init/request timeouts.
        return SdkClient::builder()
            ->setClientInfo(self::CLIENT_NAME, self::CLIENT_VERSION)
            ->setInitTimeout(max(1, (int) ($server->startupTimeoutMs / 1000)))
            ->setRequestTimeout(max(1, (int) ($server->timeoutMs / 1000)))
            ->build();
    }
}
