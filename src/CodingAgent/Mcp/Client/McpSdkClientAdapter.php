<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

use Mcp\Client as SdkClient;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;

/**
 * Hatfield adapter wrapping the official PHP MCP SDK client.
 *
 * This is the ONLY class outside the Mcp\Client namespace that may
 * import `Mcp\*` vendor types. It translates between the vendor API
 * and the Hatfield {@see McpClientInterface} contract.
 *
 * All return types are Hatfield-owned or PHP-native arrays,
 * ensuring no vendor types leak to callers.
 */
final class McpSdkClientAdapter implements McpClientInterface
{
    /**
     * @param SdkClient          $client    Pre-built SDK client (from McpSdkClientFactory)
     * @param TransportInterface $transport Pre-built transport (from McpSdkClientFactory)
     */
    public function __construct(
        private readonly SdkClient $client,
        private readonly TransportInterface $transport,
    ) {
    }

    public function connect(): void
    {
        $this->client->connect($this->transport);
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    /**
     * @return list<array{name: string, description?: string|null, inputSchema: array}>
     */
    public function listTools(): array
    {
        $result = $this->client->listTools();

        $tools = [];
        foreach ($result->tools as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'inputSchema' => $tool->inputSchema,
            ];
        }

        // Return type matches McpClientInterface::listTools(): list<array{name, description, inputSchema}>
        // The array shapes are derived from SDK Tool properties — inputSchema is a JSON Schema shape.
        return $tools;
    }

    /**
     * @return array{content: list<array<string, mixed>>}
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $result = $this->client->callTool($name, $arguments);

        $content = [];
        foreach ($result->content as $item) {
            $entry = ['type' => $item->type];

            if ($item instanceof TextContent) {
                $entry['text'] = $item->text;
            } elseif ($item instanceof ImageContent) {
                $entry['data'] = $item->data;
                $entry['mimeType'] = $item->mimeType;
            }

            $content[] = $entry;
        }

        return ['content' => $content];
    }
}
