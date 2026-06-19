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

        $this->assertSame('Hello MCP', $result);
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

        $this->assertSame("First line\nSecond line", $result);
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
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Error detail', $e->getMessage());
            $this->assertFalse($e->retryable());
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

        $this->assertStringContainsString('[MCP image:', $result);
        $this->assertStringContainsString('image/png', $result);
        $this->assertStringNotContainsString('fakebytes', $result, 'Raw binary should not appear in output');
    }

    public function testMapsAudioContentToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'audio', 'data' => 'soundbytes', 'mimeType' => 'audio/wav'],
            ],
            'isError' => false,
        ]);

        $this->assertStringContainsString('[MCP audio:', $result);
        $this->assertStringContainsString('audio/wav', $result);
    }

    public function testMapsResourceContentToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'resource', 'resource' => ['uri' => 'file:///tmp/out.txt']],
            ],
            'isError' => false,
        ]);

        $this->assertStringContainsString('[MCP resource:', $result);
        $this->assertStringContainsString('file:///tmp/out.txt', $result);
    }

    public function testMapsUnknownContentTypeToPlaceholder(): void
    {
        $result = $this->mapper->map([
            'content' => [
                ['type' => 'custom_binary'],
            ],
            'isError' => false,
        ]);

        $this->assertStringContainsString('[MCP content:', $result);
        $this->assertStringContainsString('custom_binary', $result);
    }

    // ── Test thesis 4: Empty content → ToolCallException ──

    public function testEmptyContentThrowsToolCallException(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('empty content');

        $this->mapper->map([
            'content' => [],
            'isError' => false,
        ]);
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

        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('[MCP image:', $result);
        $this->assertStringContainsString('World', $result);
    }
}
