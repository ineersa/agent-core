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
    /** Maximum allowable length for error text extracted from MCP content. */
    private const MAX_ERROR_TEXT_LENGTH = 500;

    /**
     * Map a raw MCP callTool result to a Hatfield tool output string.
     *
     * @param array{content: list<array<string, mixed>>, isError: bool} $rawResult
     *
     * @throws ToolCallException when isError is true
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
     * Empty successful content returns ''.
     *
     * @param list<array<string, mixed>> $content
     */
    private function mergeTextContent(array $content): string
    {
        if ([] === $content) {
            return '';
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
                $rawUri = \is_array($resource) && isset($resource['uri']) ? (string) $resource['uri'] : 'unknown';
                $uri = $this->redactUriCredentials($rawUri);
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
     * Truncates to MAX_ERROR_TEXT_LENGTH and redacts common secret
     * patterns so MCP server error text never exposes credentials
     * or raw secrets to LLM-visible ToolCallException messages.
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

        $joined = implode('; ', $texts);

        // Truncate long error messages — MCP servers may return
        // arbitrary-length text that should not appear verbatim in
        // LLM-visible exception messages.
        if (\strlen($joined) > self::MAX_ERROR_TEXT_LENGTH) {
            $joined = substr($joined, 0, self::MAX_ERROR_TEXT_LENGTH - 3).'...';
        }

        // Redact common secret-bearing patterns (Bearer tokens,
        // API keys, passwords).  Use the same patterns as the
        // connection manager's SECRET_PATTERNS for consistency.
        return \Ineersa\CodingAgent\Mcp\Client\McpConnectionManager::sanitizeLogMessage($joined);
    }

    /**
     * Redact credentials from a resource URI before including it
     * in tool output.
     */
    private function redactUriCredentials(string $uri): string
    {
        $parts = @parse_url($uri);

        if (false === $parts) {
            return 'unknown';
        }

        $cleaned = '';
        if (isset($parts['scheme'])) {
            $cleaned .= $parts['scheme'].'://';
        }
        if (isset($parts['host'])) {
            // Omit user:pass — never include credentials in tool output
            $cleaned .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $cleaned .= ':'.$parts['port'];
        }
        if (isset($parts['path'])) {
            $cleaned .= $parts['path'];
        }
        if (isset($parts['query'])) {
            $cleaned .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $cleaned .= '#'.$parts['fragment'];
        }

        return '' !== $cleaned ? $cleaned : 'unknown';
    }
}
