<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Client;

use Mcp\Client as SdkClient;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException as SdkConnectionException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;

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
        try {
            $this->client->connect($this->transport);
        } catch (SdkConnectionException $e) {
            throw new McpClientConnectionException($e->getMessage(), $e->getCode(), $e);
        }
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
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $result = $this->client->callTool($name, $arguments);

        $content = [];
        foreach ($result->content as $item) {
            $content[] = $this->mapContent($item);
        }

        return [
            'content' => $content,
            'isError' => $result->isError,
        ];
    }

    /**
     * Map a single SDK Content object to a PHP-native array.
     *
     * Handles all currently known MCP content types.
     * Throws for unsupported types to avoid silent data loss.
     *
     * @return array<string, mixed>
     */
    private function mapContent(Content $item): array
    {
        if ($item instanceof TextContent) {
            return [
                'type' => 'text',
                'text' => $item->text,
            ];
        }

        if ($item instanceof ImageContent) {
            return [
                'type' => 'image',
                'data' => $item->data,
                'mimeType' => $item->mimeType,
            ];
        }

        if ($item instanceof AudioContent) {
            return [
                'type' => 'audio',
                'data' => $item->data,
                'mimeType' => $item->mimeType,
            ];
        }

        if ($item instanceof EmbeddedResource) {
            return $this->mapEmbeddedResource($item);
        }

        throw new \RuntimeException(\sprintf('Unsupported MCP content type: "%s". Cannot safely map to native array.', $item->type));
    }

    /**
     * Map an EmbeddedResource to a PHP-native array, resolving the nested
     * TextResourceContents or BlobResourceContents.
     *
     * @return array<string, mixed>
     */
    private function mapEmbeddedResource(EmbeddedResource $item): array
    {
        $entry = ['type' => 'resource'];

        $resource = $item->resource;

        if ($resource instanceof TextResourceContents) {
            $entry['resource'] = [
                'uri' => $resource->uri,
                'text' => $resource->text,
            ];
            if (null !== $resource->mimeType) {
                $entry['resource']['mimeType'] = $resource->mimeType;
            }
        } elseif ($resource instanceof BlobResourceContents) {
            $entry['resource'] = [
                'uri' => $resource->uri,
                'blob' => $resource->blob,
            ];
            if (null !== $resource->mimeType) {
                $entry['resource']['mimeType'] = $resource->mimeType;
            }
        } else {
            throw new \RuntimeException(\sprintf('Unsupported embedded resource type: "%s".', $resource::class));
        }

        return $entry;
    }
}
