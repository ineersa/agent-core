<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;

/**
 * Maps MCP callTool results (content blocks + isError flag) to
 * normal Hatfield tool output strings or structured ToolCallException.
 *
 * Text content is returned as a string (joined by newlines when there
 * are multiple text blocks).  Non-text content produces a textual
 * diagnostic placeholder — raw binary/secrets are never included
 * in logs or tool output.
 *
 * SDK errors, isError=true responses, and missing clients become
 * ToolCallException so the existing ToolExecutor pipeline produces
 * a failed ToolCallResult.
 */
final class McpResultMapper
{
    /**
     * Map a raw MCP callTool result to a Hatfield tool output string.
     *
     * @param array{content: list<array<string, mixed>>, isError: bool} $rawResult
     *
     * @throws ToolCallException when isError is true or no text content is available
     */
    public function map(array $rawResult): string
    {
        if (true === $rawResult['isError']) {
            $errorText = $this->extractErrorText($rawResult['content']);
            throw new ToolCallException(error: '' !== $errorText ? \sprintf('MCP tool returned an error: %s', $errorText) : 'MCP tool returned an error (no detail available).', retryable: false, hint: 'The MCP server reported an error for this tool call.');
        }

        return $this->mergeTextContent($rawResult['content']);
    }

    /**
     * Merge text content blocks into a single string.
     *
     * Non-text blocks produce a diagnostic placeholder.
     *
     * @param list<array<string, mixed>> $content
     */
    private function mergeTextContent(array $content): string
    {
        if ([] === $content) {
            throw new ToolCallException(error: 'MCP tool returned empty content.', retryable: false, hint: 'The MCP server produced no output for this tool call.');
        }

        $parts = [];

        foreach ($content as $block) {
            $type = \is_string($block['type'] ?? null) ? $block['type'] : 'unknown';

            if ('text' === $type) {
                $parts[] = \is_string($block['text'] ?? null) ? $block['text'] : '';
            } elseif ('image' === $type) {
                $parts[] = \sprintf(
                    '[MCP image: %s, %d bytes]',
                    \is_string($block['mimeType'] ?? null) ? $block['mimeType'] : 'unknown',
                    \is_string($block['data'] ?? null) ? \strlen($block['data']) : 0,
                );
            } elseif ('audio' === $type) {
                $parts[] = \sprintf(
                    '[MCP audio: %s, %d bytes]',
                    \is_string($block['mimeType'] ?? null) ? $block['mimeType'] : 'unknown',
                    \is_string($block['data'] ?? null) ? \strlen($block['data']) : 0,
                );
            } elseif ('resource' === $type) {
                $resource = $block['resource'] ?? [];
                $uri = \is_array($resource) && isset($resource['uri']) ? (string) $resource['uri'] : 'unknown';
                $parts[] = \sprintf('[MCP resource: %s]', $uri);
            } else {
                $parts[] = \sprintf('[MCP content: type="%s"]', $type);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Extract a human-readable error string from error content blocks.
     *
     * @param list<array<string, mixed>> $content
     */
    private function extractErrorText(array $content): string
    {
        $texts = [];

        foreach ($content as $block) {
            $type = \is_string($block['type'] ?? null) ? $block['type'] : 'unknown';
            if ('text' === $type && \is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return implode('; ', $texts);
    }
}
