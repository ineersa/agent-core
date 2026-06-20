<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Mcp\Tool\McpResultMapper;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: Text content is returned as a string; multiple text blocks
 * are joined by newlines.
 *
 * Test thesis 2: isError=true becomes ToolCallException.
 *
 * Test thesis 3: Non-text content (image/audio/resource) produces diagnostic
 * placeholders without raw binary.
 *
 * Test thesis 4: Empty content throws ToolCallException.
 */
final class McpResultMapperTest extends TestCase
{
    private McpResultMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new McpResultMapper();
    }

    // ── Test thesis 1: Text content → string ──

    public function testMapsSingleTextBlock(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'text', 'text' => 'Hello MCP'],
            ],
            'isError' => false,
        ]);

        self::assertSame('Hello MCP', $result);
    }

    public function testJoinsMultipleTextBlocksWithNewline(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'text', 'text' => 'First line'],
                ['type' => 'text', 'text' => 'Second line'],
            ],
            'isError' => false,
        ]);

        self::assertSame("First line\nSecond line", $result);
    }

    // ── Test thesis 2: isError → ToolCallException ──

    public function testIsErrorTrueThrowsToolCallException(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP tool returned an error');

        $this->mapper->map([
            'content' => [
                ['type' => 'text', 'text' => 'Something went wrong'],
            ],
            'isError' => true,
        ]);
    }

    public function testIsErrorTrueExtractsTextFromErrorBlocks(): void
    {
        try {
            $this->mapper->map([
                'content' => [
                    ['type' => 'text', 'text' => 'Error detail'],
                ],
                'isError' => true,
            ]);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('Error detail', $e->getMessage());
            self::assertFalse($e->retryable());
        }
    }

    // ── Test thesis 3: Non-text content → diagnostic placeholders ──

    public function testMapsImageContentToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'image', 'data' => 'fakebytes', 'mimeType' => 'image/png'],
            ],
            'isError' => false,
        ]);

        self::assertStringContainsString('[MCP image:', $result);
        self::assertStringContainsString('image/png', $result);
        self::assertStringNotContainsString('fakebytes', $result, 'Raw binary should not appear in output');
    }

    public function testMapsAudioContentToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'audio', 'data' => 'soundbytes', 'mimeType' => 'audio/wav'],
            ],
            'isError' => false,
        ]);

        self::assertStringContainsString('[MCP audio:', $result);
        self::assertStringContainsString('audio/wav', $result);
    }

    public function testMapsResourceContentToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'resource', 'resource' => ['uri' => 'file:///tmp/out.txt']],
            ],
            'isError' => false,
        ]);

        self::assertStringContainsString('[MCP resource:', $result);
        self::assertStringContainsString('file:///tmp/out.txt', $result);
    }

    public function testMapsUnknownContentTypeToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'custom_binary'],
            ],
            'isError' => false,
        ]);

        self::assertStringContainsString('[MCP content:', $result);
        self::assertStringContainsString('custom_binary', $result);
    }

    // ── Test thesis 4: Empty content handling ──

    public function testEmptyContentWithIsErrorFalseReturnsEmptyString(): void
    {
        $result = $this->mapper->map([
            'content' => [],
            'isError' => false,
        ]);

        self::assertSame('', $result);
    }

    public function testEmptyContentWithIsErrorTrueThrowsToolCallException(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP tool returned an error');

        $this->mapper->map([
            'content' => [],
            'isError' => true,
        ]);
    }

    // ── Sanitization: error text is truncated and redacted ──

    public function testTruncatesLongErrorText(): void
    {
        $longText = str_repeat('x', 600);

        try {
            $this->mapper->map([
                'content' => [
                    ['type' => 'text', 'text' => $longText],
                ],
                'isError' => true,
            ]);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringEndsWith('...', $e->getMessage());
            self::assertLessThanOrEqual(600, \strlen($e->getMessage()));
        }
    }

    public function testRedactsBearerTokenInErrorText(): void
    {
        try {
            $this->mapper->map([
                'content' => [
                    ['type' => 'text', 'text' => 'Authorization: Bearer sk-abc123secret'],
                ],
                'isError' => true,
            ]);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('redacted', $e->getMessage());
            self::assertStringNotContainsString('sk-abc123secret', $e->getMessage());
        }
    }

    public function testRedactsApiKeyInErrorText(): void
    {
        try {
            $this->mapper->map([
                'content' => [
                    ['type' => 'text', 'text' => 'Failed with api_key=abcdef123456'],
                ],
                'isError' => true,
            ]);
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('redacted', $e->getMessage());
            self::assertStringNotContainsString('abcdef123456', $e->getMessage());
        }
    }

    // ── URI redaction ──

    public function testRedactsCredentialsFromResourceUri(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'resource', 'resource' => ['uri' => 'https://user:pass@example.com/file.txt']],
            ],
            'isError' => false,
        ]);

        self::assertStringContainsString('[MCP resource:', $result);
        self::assertStringContainsString('example.com', $result);
        self::assertStringNotContainsString('user:pass', $result, 'Credentials must not appear in tool output');
    }

    // ── Mixed content produces correct output ──

    public function testMixedContentProducesCombinedOutput(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'text', 'text' => 'Hello'],
                ['type' => 'image', 'data' => 'pngbytes', 'mimeType' => 'image/png'],
                ['type' => 'text', 'text' => 'World'],
            ],
            'isError' => false,
        ]);

        self::assertStringContainsString('Hello', $result);
        self::assertStringContainsString('[MCP image:', $result);
        self::assertStringContainsString('World', $result);
    }
}
