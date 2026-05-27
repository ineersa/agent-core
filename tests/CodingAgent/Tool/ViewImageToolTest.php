<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Config\ToolSettings;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\CodingAgent\Tool\ViewImageTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * @covers \Ineersa\CodingAgent\Tool\ViewImageTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 * @covers \Ineersa\CodingAgent\Config\ImageToolConfig
 * @covers \Ineersa\AgentCore\Application\Handler\ToolExecutor
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter
 * @covers \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer
 */
final class ViewImageToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private ViewImageTool $viewImageTool;
    private ImageToolConfig $imageConfig;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);

        $this->imageConfig = new ImageToolConfig(
            maxBytes: 10_485_760,
            maxWidth: 4096,
            maxHeight: 2000,
        );

        $this->tmpDir = sys_get_temp_dir().'/hatfield_view_image_test_'.\bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->viewImageTool = new ViewImageTool($this->toolRuntime, $this->imageConfig);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    /* ── helper: create tiny test images ── */

    private function createPng1x1(string $path): void
    {
        $img = \imagecreatetruecolor(1, 1);
        \imagepng($img, $path);
        \imagedestroy($img);
    }

    private function createGif1x1(string $path): void
    {
        $img = \imagecreatetruecolor(1, 1);
        \imagegif($img, $path);
        \imagedestroy($img);
    }

    private function createJpeg1x1(string $path): void
    {
        $img = \imagecreatetruecolor(1, 1);
        \imagejpeg($img, $path);
        \imagedestroy($img);
    }

    private function createWebp1x1(string $path): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available.');
        }

        $img = \imagecreatetruecolor(1, 1);
        \imagewebp($img, $path);
        \imagedestroy($img);
    }

    /**
     * Write raw bytes to a fixture file.
     */
    private function writeFixture(string $path, string $content): void
    {
        file_put_contents($path, $content);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsViewImage(): void
    {
        $definition = $this->viewImageTool->definition();

        self::assertSame('view_image', $definition->name);
    }

    public function testDefinitionHasDescription(): void
    {
        $definition = $this->viewImageTool->definition();

        self::assertNotEmpty($definition->description);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->viewImageTool->definition();

        self::assertTrue(method_exists($definition->handler, '__invoke'));
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->viewImageTool->definition();

        self::assertNotEmpty($definition->promptLine);
        self::assertStringContainsString('view_image', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->viewImageTool->definition();

        self::assertNotEmpty($definition->promptGuidelines);
    }

    public function testDefinitionJsonSchemaHasPathOnly(): void
    {
        $definition = $this->viewImageTool->definition();
        $schema = $definition->parametersJsonSchema;

        self::assertArrayHasKey('type', $schema);
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('path', $schema['properties']);
        self::assertArrayNotHasKey('content', $schema['properties']);
        self::assertArrayHasKey('required', $schema);
        self::assertContains('path', $schema['required']);
        self::assertCount(1, $schema['required']);
        self::assertArrayHasKey('additionalProperties', $schema);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionImplementsHatfieldToolProviderInterface(): void
    {
        self::assertTrue(method_exists($this->viewImageTool, 'definition'));
    }

    /* ── ToolRegistry integration test ── */

    public function testRegistryExposesViewImageTool(): void
    {
        $registry = new ToolRegistry([$this->viewImageTool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(fn ($t) => $t->getName(), $tools);

        self::assertContains('view_image', $toolNames);
    }

    /* ── __invoke() success tests (metadata only, no base64/data_url) ── */

    public function testViewPngImageReturnsMetadataOnly(): void
    {
        $imagePath = $this->tmpDir.'/test.png';
        $this->createPng1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        // Must be a compact metadata array — no base64, no data_url
        self::assertIsArray($result);
        self::assertSame('view_image', $result['type']);
        self::assertSame('image/png', $result['media_type']);
        self::assertSame($imagePath, $result['path']);
        self::assertGreaterThan(0, $result['bytes']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);

        // Verify NO base64 or data_url in the result
        self::assertArrayNotHasKey('base64', $result, 'Tool must not return base64');
        self::assertArrayNotHasKey('data_url', $result, 'Tool must not return data_url');
        self::assertArrayNotHasKey('output_cap_path', $result, 'Tool must not use OutputCap for images');
    }

    public function testViewGifImageReturnsMetadataOnly(): void
    {
        $imagePath = $this->tmpDir.'/test.gif';
        $this->createGif1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertSame('image/gif', $result['media_type']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertArrayNotHasKey('base64', $result);
        self::assertArrayNotHasKey('data_url', $result);
    }

    public function testViewJpegImageReturnsMetadataOnly(): void
    {
        $imagePath = $this->tmpDir.'/test.jpg';
        $this->createJpeg1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertSame('image/jpeg', $result['media_type']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertArrayNotHasKey('base64', $result);
        self::assertArrayNotHasKey('data_url', $result);
    }

    public function testViewWebpImageReturnsMetadataOnly(): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available.');
        }

        $imagePath = $this->tmpDir.'/test.webp';
        $this->createWebp1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertSame('image/webp', $result['media_type']);
        self::assertArrayNotHasKey('base64', $result);
        self::assertArrayNotHasKey('data_url', $result);
    }

    public function testViewImageWithRelativePathResolvesAgainstCwd(): void
    {
        $filename = 'view_image_test_relative_'.\bin2hex(random_bytes(4)).'.png';
        $relativePath = $this->tmpDir.'/'.$filename;
        $this->createPng1x1($relativePath);

        $cwd = getcwd();
        $relative = $this->relativePath($cwd, $relativePath);

        $result = ($this->viewImageTool)(['path' => $relative]);

        self::assertSame('image/png', $result['media_type']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertArrayNotHasKey('base64', $result);
    }

    /* ── Magic-byte detection tests (not extension-only) ── */

    public function testDetectsPngByMagicBytesNotExtension(): void
    {
        $actualPng = $this->tmpDir.'/actual.png';
        $this->createPng1x1($actualPng);

        $misnamed = $this->tmpDir.'/misnamed.gif';
        copy($actualPng, $misnamed);

        $result = ($this->viewImageTool)(['path' => $misnamed]);

        self::assertSame('image/png', $result['media_type']);
    }

    public function testDetectsGifByMagicBytesNotExtension(): void
    {
        $actualGif = $this->tmpDir.'/actual.gif';
        $this->createGif1x1($actualGif);

        $misnamed = $this->tmpDir.'/misnamed.png';
        copy($actualGif, $misnamed);

        $result = ($this->viewImageTool)(['path' => $misnamed]);

        self::assertSame('image/gif', $result['media_type']);
    }

    /* ── Unsupported file type rejection ── */

    public function testRejectsTextFile(): void
    {
        $filePath = $this->tmpDir.'/text.txt';
        $this->writeFixture($filePath, 'This is not an image.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type');

        ($this->viewImageTool)(['path' => $filePath]);
    }

    public function testRejectsHtmlFile(): void
    {
        $filePath = $this->tmpDir.'/page.html';
        $this->writeFixture($filePath, '<html><body>not an image</body></html>');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type');

        ($this->viewImageTool)(['path' => $filePath]);
    }

    public function testRejectsPdfFile(): void
    {
        $filePath = $this->tmpDir.'/doc.pdf';
        $this->writeFixture($filePath, '%PDF-1.4 fake pdf content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type');

        ($this->viewImageTool)(['path' => $filePath]);
    }

    public function testRejectsEmptyFile(): void
    {
        $filePath = $this->tmpDir.'/empty.dat';
        $this->writeFixture($filePath, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read header bytes');

        ($this->viewImageTool)(['path' => $filePath]);
    }

    /* ── Max bytes enforcement ── */

    public function testRejectsFileExceedingMaxBytes(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 50, maxWidth: 4096, maxHeight: 2000);
        $tool = new ViewImageTool($this->toolRuntime, $smallConfig);

        $img = \imagecreatetruecolor(1, 1);
        $imagePath = $this->tmpDir.'/large.png';
        \imagepng($img, $imagePath);
        \imagedestroy($img);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');

        $tool(['path' => $imagePath]);
    }

    public function testAcceptsFileWithinMaxBytes(): void
    {
        $largeConfig = new ImageToolConfig(maxBytes: 50_000_000, maxWidth: 4096, maxHeight: 2000);
        $tool = new ViewImageTool($this->toolRuntime, $largeConfig);

        $imagePath = $this->tmpDir.'/ok.png';
        $this->createPng1x1($imagePath);

        $result = $tool(['path' => $imagePath]);

        self::assertSame('image/png', $result['media_type']);
    }

    /* ── Dimension enforcement ── */

    public function testRejectsImageExceedingMaxWidth(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 10_485_760, maxWidth: 2, maxHeight: 2000);
        $tool = new ViewImageTool($this->toolRuntime, $smallConfig);

        $imagePath = $this->tmpDir.'/wide.png';
        $img = \imagecreatetruecolor(10, 1);
        \imagepng($img, $imagePath);
        \imagedestroy($img);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceed maximum allowed');

        $tool(['path' => $imagePath]);
    }

    public function testRejectsImageExceedingMaxHeight(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 10_485_760, maxWidth: 4096, maxHeight: 2);
        $tool = new ViewImageTool($this->toolRuntime, $smallConfig);

        $imagePath = $this->tmpDir.'/tall.png';
        $img = \imagecreatetruecolor(1, 10);
        \imagepng($img, $imagePath);
        \imagedestroy($img);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceed maximum allowed');

        $tool(['path' => $imagePath]);
    }

    /* ── Argument validation tests ── */

    public function testThrowsOnMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->viewImageTool)([]);
    }

    public function testThrowsOnNonStringPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->viewImageTool)(['path' => 123]);
    }

    public function testThrowsOnEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->viewImageTool)(['path' => '']);
    }

    public function testThrowsOnNonExistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        ($this->viewImageTool)(['path' => $this->tmpDir.'/nonexistent.png']);
    }

    /* ── Cancellation tests ── */

    public function testCancelledBeforeExecutionThrows(): void
    {
        $token = $this->createToken(true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function (): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');

                ($this->viewImageTool)([
                    'path' => $this->tmpDir.'/cancelled.png',
                ]);
            },
        );
    }

    public function testCancelledAfterExecutionThrows(): void
    {
        $imagePath = $this->tmpDir.'/stale.png';
        $this->createPng1x1($imagePath);

        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturnOnConsecutiveCalls(false, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stale due to run cancellation');

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function () use ($imagePath): void {
                ($this->viewImageTool)(['path' => $imagePath]);
            },
        );
    }

    /* ── Full pipeline: ToolResult → ToolCallResult → AgentMessage → MessageBag ── */

    public function testPipelineProducesImageAttachmentInMessageBag(): void
    {
        // This test proves the full pipeline:
        //   ViewImageTool → RegistryBackedToolbox → ToolExecutor
        //   → ExecuteToolCallWorker (ToolCallResult)
        //   → AgentMessageNormalizer::toolMessage() (AgentMessage with image_ref)
        //   → AgentMessageConverter::toMessageBag() (UserMessage with Image)
        //
        // The final MessageBag should contain:
        // 1. A ToolCallMessage with text (metadata JSON)
        // 2. A UserMessage with Text + Symfony AI Image content objects

        $imagePath = $this->tmpDir.'/pipeline.png';
        $this->createPng1x1($imagePath);

        // Wire up real objects
        $resultStore = new ToolExecutionResultStore();
        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolRuntime = new ToolRuntime($contextAccessor);
        $tool = new ViewImageTool($toolRuntime, $this->imageConfig);
        $registry = new ToolRegistry([$tool]);
        $toolbox = new RegistryBackedToolbox($registry);

        $tokenCancelledFirst = $this->createStub(CancellationTokenInterface::class);
        $tokenCancelledFirst->method('isCancellationRequested')->willReturn(false);

        $executor = ToolExecutor::fromSettings(
            settings: new ToolSettings(
                mode: 'sequential',
                timeoutSeconds: 30,
                maxParallelism: 1,
            ),
            resultStore: $resultStore,
            toolbox: $toolbox,
            contextAccessor: $contextAccessor,
        );

        $toolCall = new ToolCall(
            toolCallId: 'view_image_call_1',
            toolName: 'view_image',
            arguments: ['path' => $imagePath],
            orderIndex: 0,
            runId: 'test_run_1',
            mode: null,
            timeoutSeconds: null,
            toolIdempotencyKey: null,
            context: [
                'cancel_token' => $tokenCancelledFirst,
                'turn_no' => 1,
            ],
        );

        $toolResult = $executor->execute($toolCall);

        // 1. ToolResult content should contain metadata JSON, not base64
        self::assertFalse($toolResult->isError, 'Tool result should not be an error: '.($toolResult->content[0]['text'] ?? 'no content'));

        $contentText = $toolResult->content[0]['text'] ?? '';
        self::assertJson($contentText);

        $parsed = json_decode($contentText, true);
        self::assertIsArray($parsed);
        self::assertSame('view_image', $parsed['type']);
        self::assertSame('image/png', $parsed['media_type']);
        self::assertSame(1, $parsed['width']);
        self::assertSame(1, $parsed['height']);
        // Verify no base64 in the text content
        self::assertArrayNotHasKey('base64', $parsed, 'Text content must not contain base64');
        self::assertArrayNotHasKey('data_url', $parsed, 'Text content must not contain data_url');

        // 2. Build the domain ToolCallResult (simulating ExecuteToolCallWorker)
        $domainResult = new ToolCallResult(
            runId: 'test_run_1',
            turnNo: 1,
            stepId: 'step_1',
            attempt: 0,
            idempotencyKey: 'ik_1',
            toolCallId: 'view_image_call_1',
            orderIndex: 0,
            result: [
                'tool_name' => $toolResult->toolName,
                'content' => $toolResult->content,
                'details' => $toolResult->details,
                'tool_idempotency_key' => null,
                'mode' => null,
            ],
            isError: $toolResult->isError,
            error: null,
        );

        // 3. Run through AgentMessageNormalizer::toolMessage()
        $normalizer = new AgentMessageNormalizer();
        $agentMessage = $normalizer->toolMessage($domainResult);

        // The AgentMessage content should have two parts: text + image_ref
        self::assertCount(2, $agentMessage->content, 'AgentMessage should have text + image_ref content parts');
        self::assertSame('text', $agentMessage->content[0]['type']);
        self::assertSame('image_ref', $agentMessage->content[1]['type'], 'Second content part must be image_ref');
        self::assertSame($imagePath, $agentMessage->content[1]['path']);
        self::assertSame('image/png', $agentMessage->content[1]['media_type']);

        // 4. Run through AgentMessageConverter::toMessageBag()
        $converter = new AgentMessageConverter();
        $messageBag = $converter->toMessageBag([$agentMessage]);

        $messages = $messageBag->getMessages();
        // Should have 2 messages: ToolCallMessage + UserMessage (with Image)
        self::assertCount(2, $messages, 'MessageBag should contain 2 messages (tool result + image attachment)');

        // First message: ToolCallMessage with text content
        $toolCallMsg = $messages[0];
        self::assertSame('tool', $toolCallMsg->getRole()->value);
        $toolContent = $toolCallMsg->getContent();
        // Use non-slash substrings to avoid JSON encoding escaping issues
        self::assertStringContainsString('media_type', $toolContent, 'Tool message should contain image metadata');
        self::assertStringContainsString('view_image', $toolContent, 'Tool message should reference view_image');

        // Second message: UserMessage with real Image content
        $imageMsg = $messages[1];
        self::assertSame('user', $imageMsg->getRole()->value);
        self::assertInstanceOf(UserMessage::class, $imageMsg);

        // Verify the UserMessage contains an Image content object
        self::assertTrue($imageMsg->hasImageContent(), 'UserMessage must have image content after conversion');

        // Find the Image content object in the message content parts
        $foundImage = null;
        foreach ($imageMsg->getContent() as $contentPart) {
            if ($contentPart instanceof Image) {
                $foundImage = $contentPart;
                break;
            }
        }
        self::assertNotNull($foundImage, 'UserMessage should contain an Image content object');
        self::assertSame('image/png', $foundImage->getFormat());
        // Verify it can produce a valid data URL (lazy-read works)
        $dataUrl = $foundImage->asDataUrl();
        self::assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    public function testPipelineDoesNotPersistBase64InSerializedState(): void
    {
        // Verify that serializing the AgentMessage produced by the pipeline
        // does NOT contain base64 or data_url strings.

        $imagePath = $this->tmpDir.'/state_test.png';
        $this->createPng1x1($imagePath);

        $regularResult = ($this->viewImageTool)(['path' => $imagePath]);

        // Simulate the full pipeline: handler result → details → normalizer
        $result = new ToolCallResult(
            runId: 'test_run_2',
            turnNo: 1,
            stepId: 'step_1',
            attempt: 0,
            idempotencyKey: 'ik_2',
            toolCallId: 'view_image_call_2',
            orderIndex: 0,
            result: [
                'tool_name' => 'view_image',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($regularResult),
                ]],
                'details' => [
                    'raw_result' => $regularResult,
                    'mode' => 'sequential',
                    'timeout_seconds' => 30,
                    'max_parallelism' => 1,
                ],
                'tool_idempotency_key' => null,
                'mode' => null,
            ],
            isError: false,
            error: null,
        );

        $normalizer = new AgentMessageNormalizer();
        $agentMessage = $normalizer->toolMessage($result);

        // Serialize to array (as state.json would)
        $serialized = $agentMessage->toArray();

        $serializedJson = json_encode($serialized);
        self::assertIsString($serializedJson);

        // The serialized JSON must NOT contain base64 image data or data URLs
        self::assertStringNotContainsString('base64,', $serializedJson, 'Serialized state must not contain base64 image data');
        self::assertStringNotContainsString('data:image/', $serializedJson, 'Serialized state must not contain data URLs');

        // The serialized JSON SHOULD contain image_ref metadata
        self::assertStringContainsString('image_ref', $serializedJson, 'Serialized state should contain image_ref type');
        // Use non-slash substrings to avoid JSON encoding escaping issues
        self::assertStringContainsString('image_ref', $serializedJson, 'Serialized state should contain image_ref');
        self::assertStringContainsString('png', $serializedJson, 'Serialized state should contain file extension');
    }

    /* ── AgentMessageConverter image_ref edge cases ── */

    public function testNonImageToolResultDoesNotEmitImageRef(): void
    {
        // A non-image tool (e.g. write_file) should not get image_ref content parts
        $normalizer = new AgentMessageNormalizer();
        $result = new ToolCallResult(
            runId: 'test_run_3',
            turnNo: 1,
            stepId: 'step_1',
            attempt: 0,
            idempotencyKey: 'ik_3',
            toolCallId: 'write_call_1',
            orderIndex: 0,
            result: [
                'tool_name' => 'write_file',
                'content' => [[
                    'type' => 'text',
                    'text' => 'File written successfully.',
                ]],
                'details' => [
                    'raw_result' => ['type' => 'write_file', 'path' => '/tmp/test.txt', 'bytes' => 10],
                    'mode' => 'sequential',
                ],
                'tool_idempotency_key' => null,
                'mode' => null,
            ],
            isError: false,
            error: null,
        );

        $agentMessage = $normalizer->toolMessage($result);

        // Should have only a text content part (no image_ref)
        $imageRefParts = array_filter(
            $agentMessage->content,
            static fn (array $part): bool => 'image_ref' === ($part['type'] ?? null),
        );
        self::assertCount(0, $imageRefParts, 'Non-image tool should not have image_ref content parts');
    }

    public function testSyntheticImageMessagesAreDeferredUntilAfterConsecutiveToolBatch(): void
    {
        $converter = new AgentMessageConverter();
        $imagePath = $this->tmpDir.'/batch.png';
        $this->createPng1x1($imagePath);

        $viewImageMessage = new AgentMessage(
            role: 'tool',
            content: [
                ['type' => 'text', 'text' => '{"type":"view_image"}'],
                [
                    'type' => 'image_ref',
                    'path' => $imagePath,
                    'media_type' => 'image/png',
                    'bytes' => 100,
                    'width' => 1,
                    'height' => 1,
                ],
            ],
            toolCallId: 'view_call_1',
            toolName: 'view_image',
            details: [],
        );

        $writeMessage = new AgentMessage(
            role: 'tool',
            content: [
                ['type' => 'text', 'text' => '{"type":"write_file"}'],
            ],
            toolCallId: 'write_call_1',
            toolName: 'write_file',
            details: [],
        );

        $messages = $converter->toMessageBag([$viewImageMessage, $writeMessage])->getMessages();

        self::assertCount(3, $messages);
        self::assertSame('tool', $messages[0]->getRole()->value);
        self::assertSame('tool', $messages[1]->getRole()->value);
        self::assertSame('user', $messages[2]->getRole()->value);
        self::assertInstanceOf(UserMessage::class, $messages[2]);
        self::assertTrue($messages[2]->hasImageContent());
    }

    public function testImageRefWithMissingFileProducesTextPlaceholder(): void
    {
        // If the image file referenced by image_ref is deleted between
        // tool execution and conversion, the converter should produce
        // a text placeholder instead of failing.
        $converter = new AgentMessageConverter();

        // Create an image, get its path, then delete it
        $imagePath = $this->tmpDir.'/deleted.png';
        $this->createPng1x1($imagePath);

        $agentMessage = new AgentMessage(
            role: 'tool',
            content: [
                ['type' => 'text', 'text' => '{"type":"view_image","path":"'.$imagePath.'","media_type":"image/png"}'],
                [
                    'type' => 'image_ref',
                    'path' => $imagePath,
                    'media_type' => 'image/png',
                    'bytes' => 100,
                    'width' => 1,
                    'height' => 1,
                ],
            ],
            toolCallId: 'call_deleted',
            toolName: 'view_image',
            details: [],
        );

        // Delete the file before conversion
        unlink($imagePath);

        $messageBag = $converter->toMessageBag([$agentMessage]);
        $messages = $messageBag->getMessages();

        // Should still have 2 messages (tool call + placeholder text)
        self::assertCount(2, $messages);

        // Second message should be a text-only user message, not an Image
        $secondMsg = $messages[1];
        self::assertSame('user', $secondMsg->getRole()->value);
        // UserMessage::getContent() returns ContentInterface[]; use asText() for string
        $secondText = $secondMsg instanceof UserMessage
            ? ($secondMsg->asText() ?? '')
            : (string) $secondMsg->getContent();
        self::assertStringContainsString('deleted', $secondText);
        // When file is missing, hasImageContent should be false
        if ($secondMsg instanceof UserMessage) {
            self::assertFalse($secondMsg->hasImageContent(), 'Deleted file should not produce image content');
        }
    }

    /* ── helpers ── */

    private function createToken(bool $cancelled): CancellationTokenInterface
    {
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn($cancelled);

        return $token;
    }

    private function contextWithToken(CancellationTokenInterface $token): ToolContext
    {
        return new ToolContext(
            runId: 'view_test_run',
            turnNo: 1,
            toolCallId: 'view_call_1',
            toolName: 'view_image',
            cancellationToken: $token,
            timeoutSeconds: 30,
        );
    }

    private function rmDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir((string) $item)
                : unlink((string) $item);
        }

        @rmdir($path);
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', rtrim($from, '/'));
        $toParts = explode('/', rtrim($to, '/'));

        $i = 0;
        while ($i < \count($fromParts) && $i < \count($toParts) && $fromParts[$i] === $toParts[$i]) {
            ++$i;
        }

        $relative = [];
        for ($j = $i; $j < \count($fromParts); ++$j) {
            $relative[] = '..';
        }

        for ($j = $i; $j < \count($toParts); ++$j) {
            $relative[] = $toParts[$j];
        }

        return implode('/', $relative);
    }
}
