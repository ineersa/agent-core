<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Client;

use Ineersa\CodingAgent\Mcp\Client\McpSdkClientAdapter;
use Mcp\Client as SdkClient;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;

/**
 * Test that McpSdkClientAdapter correctly maps SDK types to Hatfield-native arrays.
 */
class McpSdkClientAdapterTest extends TestCase
{
    public function testCallToolReturnsIsErrorTrue(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            CallToolResult::error([new TextContent('something went wrong')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('failing');

        self::assertTrue($result['isError']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame('something went wrong', $result['content'][0]['text']);
    }

    public function testCallToolReturnsIsErrorFalse(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult(
                content: [new TextContent('success')],
                isError: false,
            ),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('ok-tool');

        self::assertFalse($result['isError']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame('success', $result['content'][0]['text']);
    }

    public function testCallToolMapsTextContent(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult([new TextContent('hello world')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('echo');

        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame('hello world', $result['content'][0]['text']);
    }

    public function testCallToolMapsImageContent(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult([new ImageContent('base64data', 'image/png')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('screenshot');

        self::assertSame('image', $result['content'][0]['type']);
        self::assertSame('base64data', $result['content'][0]['data']);
        self::assertSame('image/png', $result['content'][0]['mimeType']);
    }

    public function testCallToolMapsAudioContent(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult([new AudioContent('base64audio', 'audio/wav')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('listen');

        self::assertSame('audio', $result['content'][0]['type']);
        self::assertSame('base64audio', $result['content'][0]['data']);
        self::assertSame('audio/wav', $result['content'][0]['mimeType']);
    }

    public function testCallToolMapsEmbeddedResourceText(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $resource = EmbeddedResource::fromText(
            uri: 'file:///app/readme.md',
            text: '# Hello',
            mimeType: 'text/markdown',
        );
        $sdkClient->method('callTool')->willReturn(new CallToolResult([$resource]));

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('read');

        self::assertSame('resource', $result['content'][0]['type']);
        self::assertSame('file:///app/readme.md', $result['content'][0]['resource']['uri']);
        self::assertSame('# Hello', $result['content'][0]['resource']['text']);
        self::assertSame('text/markdown', $result['content'][0]['resource']['mimeType']);
    }

    public function testCallToolMapsEmbeddedResourceBlob(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $resource = EmbeddedResource::fromBlob(
            uri: 'file:///app/binary.dat',
            base64Blob: 'aGVsbG8=',
            mimeType: 'application/octet-stream',
        );
        $sdkClient->method('callTool')->willReturn(new CallToolResult([$resource]));

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('fetch');

        self::assertSame('resource', $result['content'][0]['type']);
        self::assertSame('file:///app/binary.dat', $result['content'][0]['resource']['uri']);
        self::assertSame('aGVsbG8=', $result['content'][0]['resource']['blob']);
        self::assertSame('application/octet-stream', $result['content'][0]['resource']['mimeType']);
    }

    public function testCallToolMapsMultipleContentTypesTogether(): void
    {
        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            CallToolResult::error([
                new TextContent('text part'),
                new AudioContent('audiobase64', 'audio/mpeg'),
                EmbeddedResource::fromText('file:///x.md', 'md text'),
                EmbeddedResource::fromBlob('file:///y.bin', 'YmxvYg==', 'application/octet-stream'),
            ]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));
        $result = $adapter->callTool('multi');

        self::assertTrue($result['isError']);
        self::assertCount(4, $result['content']);

        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame('text part', $result['content'][0]['text']);

        self::assertSame('audio', $result['content'][1]['type']);

        self::assertSame('resource', $result['content'][2]['type']);
        self::assertArrayHasKey('text', $result['content'][2]['resource']);

        self::assertSame('resource', $result['content'][3]['type']);
        self::assertArrayHasKey('blob', $result['content'][3]['resource']);
    }

    public function testCallToolThrowsForUnsupportedContentType(): void
    {
        // Create an anonymous subclass of Content with an unsupported type
        $unknownContent = new class extends Content {
            public function __construct()
            {
                parent::__construct('custom-unknown-type');
            }

            public function jsonSerialize(): array
            {
                return ['type' => 'custom-unknown-type'];
            }
        };

        $sdkClient = self::createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(new CallToolResult([$unknownContent]));

        $adapter = new McpSdkClientAdapter($sdkClient, self::createStub(TransportInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported MCP content type: "custom-unknown-type"');

        $adapter->callTool('bad');
    }
}
