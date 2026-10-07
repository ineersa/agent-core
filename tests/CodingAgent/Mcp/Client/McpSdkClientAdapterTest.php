<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Client;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Mcp\Client\McpClientInterruptedException;
use Ineersa\CodingAgent\Mcp\Client\McpSdkClientAdapter;
use Mcp\Client as SdkClient;
use Mcp\Client\CancellationTokenInterface as SdkCancellationTokenInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\RequestCancelledException as SdkRequestCancelledException;
use Mcp\Exception\TimeoutException as SdkTimeoutException;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

/**
 * Test that McpSdkClientAdapter correctly maps SDK types to Hatfield-native arrays.
 */
class McpSdkClientAdapterTest extends TestCase
{
    public function testListToolsCollectsEveryPage(): void
    {
        $schema = ['type' => 'object', 'properties' => ['value' => ['type' => 'string']], 'required' => []];
        $pages = [
            new ListToolsResult([Tool::fromArray(['name' => 'first', 'description' => 'First tool', 'inputSchema' => $schema])], 'page-2'),
            new ListToolsResult([], 'page-3'),
            new ListToolsResult([Tool::fromArray(['name' => 'last', 'inputSchema' => $schema])]),
        ];
        $expectedCursors = [null, 'page-2', 'page-3'];

        $sdkClient = $this->createMock(SdkClient::class);
        $sdkClient->expects($this->exactly(3))->method('listTools')
            ->willReturnCallback(function (?string $cursor) use (&$pages, &$expectedCursors): ListToolsResult {
                $this->assertSame(array_shift($expectedCursors), $cursor);

                return array_shift($pages);
            });

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));

        $this->assertSame([
            ['name' => 'first', 'description' => 'First tool', 'inputSchema' => $schema],
            ['name' => 'last', 'description' => null, 'inputSchema' => $schema],
        ], $adapter->listTools());
    }

    public function testListToolsRejectsRepeatedCursor(): void
    {
        $sdkClient = $this->createMock(SdkClient::class);
        $sdkClient->expects($this->exactly(3))->method('listTools')
            ->willReturnCallback(static function (?string $cursor): ListToolsResult {
                return match ($cursor) {
                    null => new ListToolsResult([], 'page-2'),
                    'page-2' => new ListToolsResult([], 'page-3'),
                    'page-3' => new ListToolsResult([], 'page-2'),
                    default => throw new \LogicException('Unexpected cursor'),
                };
            });

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP tools/list repeated cursor');

        $adapter->listTools();
    }

    public function testCallToolReturnsIsErrorTrue(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            CallToolResult::error([new TextContent('something went wrong')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('failing');

        $this->assertTrue($result['isError']);
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('something went wrong', $result['content'][0]['text']);
    }

    public function testCallToolCapsBudgetAndPassesCancellationToken(): void
    {
        $token = new class implements CancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $sdkClient = $this->createMock(SdkClient::class);
        $sdkClient->expects($this->once())->method('callTool')
            ->willReturnCallback(function (string $name, array $arguments, ?callable $onProgress, ?SdkCancellationTokenInterface $cancellation, ?float $timeout) use ($token): CallToolResult {
                $this->assertSame('slow', $name);
                $this->assertSame(0.5, $timeout);
                $this->assertNotNull($cancellation);
                $this->assertFalse($cancellation->isCancellationRequested());

                $token->cancelled = true;
                $nextPollAt = new \ReflectionProperty($cancellation, 'nextPollAt');
                $nextPollAt->setValue($cancellation, 0);
                $this->assertTrue($cancellation->isCancellationRequested());
                $this->assertTrue($cancellation->isCancellationRequested());

                return new CallToolResult([new TextContent('done')]);
            });

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class), serverTimeoutSeconds: 0.5);
        $this->assertFalse($adapter->callTool('slow', cancellationToken: $token, timeoutSeconds: 2)['isError']);
    }

    public function testCallToolMapsSdkCancellationAndTimeoutToInterrupted(): void
    {
        $sdkClient = $this->createMock(SdkClient::class);
        $sdkClient->expects($this->exactly(2))->method('callTool')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new SdkRequestCancelledException('The client cancelled the request.')),
                $this->throwException(new SdkTimeoutException('The request deadline expired.')),
            );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class), serverTimeoutSeconds: 1.0);

        try {
            $adapter->callTool('slow');
            $this->fail('SDK cancellation must become McpClientInterruptedException.');
        } catch (McpClientInterruptedException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
            $this->assertInstanceOf(SdkRequestCancelledException::class, $e->getPrevious());
        }

        try {
            $adapter->callTool('slow');
            $this->fail('SDK timeout must become McpClientInterruptedException.');
        } catch (McpClientInterruptedException $e) {
            $this->assertSame('The request deadline expired.', $e->getMessage());
            $this->assertInstanceOf(SdkTimeoutException::class, $e->getPrevious());
        }
    }

    public function testCallToolReturnsIsErrorFalse(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult(
                content: [new TextContent('success')],
                isError: false,
            ),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('ok-tool');

        $this->assertFalse($result['isError']);
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('success', $result['content'][0]['text']);
    }

    public function testCallToolMapsTextContent(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult([new TextContent('hello world')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('echo');

        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('hello world', $result['content'][0]['text']);
    }

    public function testCallToolMapsImageContent(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult([new ImageContent('base64data', 'image/png')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('screenshot');

        $this->assertSame('image', $result['content'][0]['type']);
        $this->assertSame('base64data', $result['content'][0]['data']);
        $this->assertSame('image/png', $result['content'][0]['mimeType']);
    }

    public function testCallToolMapsAudioContent(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            new CallToolResult([new AudioContent('base64audio', 'audio/wav')]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('listen');

        $this->assertSame('audio', $result['content'][0]['type']);
        $this->assertSame('base64audio', $result['content'][0]['data']);
        $this->assertSame('audio/wav', $result['content'][0]['mimeType']);
    }

    public function testCallToolMapsEmbeddedResourceText(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $resource = EmbeddedResource::fromText(
            uri: 'file:///app/readme.md',
            text: '# Hello',
            mimeType: 'text/markdown',
        );
        $sdkClient->method('callTool')->willReturn(new CallToolResult([$resource]));

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('read');

        $this->assertSame('resource', $result['content'][0]['type']);
        $this->assertSame('file:///app/readme.md', $result['content'][0]['resource']['uri']);
        $this->assertSame('# Hello', $result['content'][0]['resource']['text']);
        $this->assertSame('text/markdown', $result['content'][0]['resource']['mimeType']);
    }

    public function testCallToolMapsEmbeddedResourceBlob(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $resource = EmbeddedResource::fromBlob(
            uri: 'file:///app/binary.dat',
            base64Blob: 'aGVsbG8=',
            mimeType: 'application/octet-stream',
        );
        $sdkClient->method('callTool')->willReturn(new CallToolResult([$resource]));

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('fetch');

        $this->assertSame('resource', $result['content'][0]['type']);
        $this->assertSame('file:///app/binary.dat', $result['content'][0]['resource']['uri']);
        $this->assertSame('aGVsbG8=', $result['content'][0]['resource']['blob']);
        $this->assertSame('application/octet-stream', $result['content'][0]['resource']['mimeType']);
    }

    public function testCallToolMapsMultipleContentTypesTogether(): void
    {
        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(
            CallToolResult::error([
                new TextContent('text part'),
                new AudioContent('audiobase64', 'audio/mpeg'),
                EmbeddedResource::fromText('file:///x.md', 'md text'),
                EmbeddedResource::fromBlob('file:///y.bin', 'YmxvYg==', 'application/octet-stream'),
            ]),
        );

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));
        $result = $adapter->callTool('multi');

        $this->assertTrue($result['isError']);
        $this->assertCount(4, $result['content']);

        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame('text part', $result['content'][0]['text']);

        $this->assertSame('audio', $result['content'][1]['type']);

        $this->assertSame('resource', $result['content'][2]['type']);
        $this->assertArrayHasKey('text', $result['content'][2]['resource']);

        $this->assertSame('resource', $result['content'][3]['type']);
        $this->assertArrayHasKey('blob', $result['content'][3]['resource']);
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

        $sdkClient = $this->createStub(SdkClient::class);
        $sdkClient->method('callTool')->willReturn(new CallToolResult([$unknownContent]));

        $adapter = new McpSdkClientAdapter($sdkClient, $this->createStub(TransportInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported MCP content type: "custom-unknown-type"');

        $adapter->callTool('bad');
    }
}
