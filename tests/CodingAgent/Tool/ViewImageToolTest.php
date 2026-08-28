<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Model\ImageCapabilityCheckerInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Config\ToolSettings;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tests\Tool\Support\ToolValidationHarness;
use Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\RunVisionCheckService;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\CodingAgent\Tool\Validation\ViewImage\ViewImageTargetValidator;
use Ineersa\CodingAgent\Tool\ViewImageTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ValidatorBuilder;

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

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_view_image_test');

        $this->viewImageTool = new ViewImageTool($this->toolRuntime);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsViewImage(): void
    {
        $definition = $this->viewImageTool->definition();

        $this->assertSame('view_image', $definition->name);
    }

    /**
     * Regression (PR #387 review): view_image used to ship a leftover manual
     * flat parametersJsonSchema that misclassified it as a raw-array tool and
     * bypassed DTO resolution. The definition must now route through the
     * native DTO path and the provider schema must be the native nested
     * {arguments: {path}} shape carrying the exact crafted path description.
     */
    public function testDefinitionUsesNativeFlatSchemaWithCraftedPathDescription(): void
    {
        $definition = $this->viewImageTool->definition();

        $this->assertSame(ViewImageTool::DESCRIPTION, $definition->description);
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->viewImageTool);
        $args = $schema;

        $this->assertSame('object', $args['type']);
        $this->assertSame(
            'Path to the image file (absolute, or relative to the working directory)',
            $args['properties']['path']['description'],
        );
        $this->assertSame(['path'], $args['required']);
        $this->assertFalse($args['additionalProperties']);
    }

    /* ── __invoke() success tests (metadata only, no base64/data_url) ── */

    public function testViewPngImageReturnsMetadataOnly(): void
    {
        $imagePath = $this->tmpDir.'/test.png';
        $this->createPng1x1($imagePath);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $imagePath));

        // Must be a compact metadata array — no base64, no data_url
        $this->assertIsArray($result);
        $this->assertSame('view_image', $result['type']);
        $this->assertSame('image/png', $result['media_type']);
        $this->assertSame($imagePath, $result['path']);
        $this->assertGreaterThan(0, $result['bytes']);
        $this->assertSame(1, $result['width']);
        $this->assertSame(1, $result['height']);

        // Verify NO base64 or data_url in the result
        $this->assertArrayNotHasKey('base64', $result, 'Tool must not return base64');
        $this->assertArrayNotHasKey('data_url', $result, 'Tool must not return data_url');
        $this->assertArrayNotHasKey('output_cap_path', $result, 'Tool must not use OutputCap for images');
    }

    public function testViewGifImageReturnsMetadataOnly(): void
    {
        $imagePath = $this->tmpDir.'/test.gif';
        $this->createGif1x1($imagePath);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $imagePath));

        $this->assertSame('image/gif', $result['media_type']);
        $this->assertSame(1, $result['width']);
        $this->assertSame(1, $result['height']);
        $this->assertArrayNotHasKey('base64', $result);
        $this->assertArrayNotHasKey('data_url', $result);
    }

    public function testViewJpegImageReturnsMetadataOnly(): void
    {
        $imagePath = $this->tmpDir.'/test.jpg';
        $this->createJpeg1x1($imagePath);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $imagePath));

        $this->assertSame('image/jpeg', $result['media_type']);
        $this->assertSame(1, $result['width']);
        $this->assertSame(1, $result['height']);
        $this->assertArrayNotHasKey('base64', $result);
        $this->assertArrayNotHasKey('data_url', $result);
    }

    public function testViewWebpImageReturnsMetadataOnly(): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available.');
        }

        $imagePath = $this->tmpDir.'/test.webp';
        $this->createWebp1x1($imagePath);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $imagePath));

        $this->assertSame('image/webp', $result['media_type']);
        $this->assertArrayNotHasKey('base64', $result);
        $this->assertArrayNotHasKey('data_url', $result);
    }

    public function testViewImageWithRelativePathResolvesAgainstCwd(): void
    {
        $filename = 'view_image_test_relative_'.bin2hex(random_bytes(4)).'.png';
        $relativePath = $this->tmpDir.'/'.$filename;
        $this->createPng1x1($relativePath);

        $cwd = getcwd();
        $relative = $this->relativePath($cwd, $relativePath);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $relative));

        $this->assertSame('image/png', $result['media_type']);
        $this->assertSame(1, $result['width']);
        $this->assertSame(1, $result['height']);
        $this->assertArrayNotHasKey('base64', $result);
    }

    /* ── Magic-byte detection tests (not extension-only) ── */

    public function testDetectsPngByMagicBytesNotExtension(): void
    {
        $actualPng = $this->tmpDir.'/actual.png';
        $this->createPng1x1($actualPng);

        $misnamed = $this->tmpDir.'/misnamed.gif';
        copy($actualPng, $misnamed);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $misnamed));

        $this->assertSame('image/png', $result['media_type']);
    }

    public function testDetectsGifByMagicBytesNotExtension(): void
    {
        $actualGif = $this->tmpDir.'/actual.gif';
        $this->createGif1x1($actualGif);

        $misnamed = $this->tmpDir.'/misnamed.png';
        copy($actualGif, $misnamed);

        $result = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $misnamed));

        $this->assertSame('image/gif', $result['media_type']);
    }

    public function testRejectsTextFile(): void
    {
        $filePath = $this->tmpDir.'/text.txt';
        $this->writeFixture($filePath, 'This is not an image.');

        $result = $this->validationToolbox()->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $filePath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('Unsupported image type', $message);
        $this->assertStringContainsString('Use JPEG, PNG, GIF, or WebP format.', $message);
    }

    public function testRejectsHtmlFile(): void
    {
        $filePath = $this->tmpDir.'/page.html';
        $this->writeFixture($filePath, '<html><body>not an image</body></html>');

        $result = $this->validationToolbox()->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $filePath]));

        $this->assertStringContainsString('Unsupported image type', (string) $result->getResult());
    }

    public function testRejectsPdfFile(): void
    {
        $filePath = $this->tmpDir.'/doc.pdf';
        $this->writeFixture($filePath, '%PDF-1.4 fake pdf content');

        $result = $this->validationToolbox()->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $filePath]));

        $this->assertStringContainsString('Unsupported image type', (string) $result->getResult());
    }

    public function testRejectsEmptyFile(): void
    {
        $filePath = $this->tmpDir.'/empty.dat';
        $this->writeFixture($filePath, '');

        $result = $this->validationToolbox()->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $filePath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('Failed to read header bytes', $message);
        $this->assertStringContainsString('appears empty or unreadable', $message);
    }

    /* ── Max bytes enforcement (validation-level) ── */

    public function testRejectsFileExceedingMaxBytes(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 50, maxWidth: 4096, maxHeight: 2000);

        $img = imagecreatetruecolor(1, 1);
        $imagePath = $this->tmpDir.'/large.png';
        imagepng($img, $imagePath);
        // imagedestroy is no-op since PHP 8.0, removed

        $result = $this->validationToolbox($smallConfig)->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $imagePath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('exceeds maximum allowed size', $message);
        $this->assertStringContainsString('increase the max_bytes setting', $message);
    }

    public function testAcceptsFileWithinMaxBytes(): void
    {
        $largeConfig = new ImageToolConfig(maxBytes: 50_000_000, maxWidth: 4096, maxHeight: 2000);

        $imagePath = $this->tmpDir.'/ok.png';
        $this->createPng1x1($imagePath);

        // Valid call runs through the validator and reaches the handler.
        $result = $this->validationToolbox($largeConfig)->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $imagePath]));

        $this->assertIsArray($result->getResult());
        $this->assertSame('image/png', $result->getResult()['media_type']);
    }

    /* ── Dimension enforcement (validation-level) ── */

    public function testRejectsImageExceedingMaxWidth(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 10_485_760, maxWidth: 2, maxHeight: 2000);

        $imagePath = $this->tmpDir.'/wide.png';
        $img = imagecreatetruecolor(10, 1);
        imagepng($img, $imagePath);
        // imagedestroy is no-op since PHP 8.0, removed

        $result = $this->validationToolbox($smallConfig)->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $imagePath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('exceed maximum allowed', $message);
        $this->assertStringContainsString('max_width/max_height', $message);
    }

    public function testRejectsImageExceedingMaxHeight(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 10_485_760, maxWidth: 4096, maxHeight: 2);

        $imagePath = $this->tmpDir.'/tall.png';
        $img = imagecreatetruecolor(1, 10);
        imagepng($img, $imagePath);
        // imagedestroy is no-op since PHP 8.0, removed

        $result = $this->validationToolbox($smallConfig)->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $imagePath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('exceed maximum allowed', $message);
        $this->assertStringContainsString('max_width/max_height', $message);
    }

    /* ── Static argument validation lives in the DTO (enforced by the native
           ValidateToolCallArgumentsListener before the handler runs) ── */

    public function testDtoRejectsBlankPath(): void
    {
        $violations = $this->validateDto(new ViewImageArgumentsDTO());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"path" argument is required', $violations[0]->getMessage());
    }

    public function testDtoRejectsBlankPathWithWhitespace(): void
    {
        $violations = $this->validateDto(new ViewImageArgumentsDTO(path: '   '));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"path" argument is required', $violations[0]->getMessage());
    }

    public function testThrowsOnNonExistentFile(): void
    {
        $result = $this->validationToolbox()->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $this->tmpDir.'/nonexistent.png']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist or is not readable', $message);
        $this->assertStringContainsString('Use absolute paths or paths relative to the working directory.', $message);
    }

    public function testNonVisionModelIsRejectedByValidation(): void
    {
        $imagePath = $this->tmpDir.'/nonvision.png';
        $this->createPng1x1($imagePath);

        $visionCheck = $this->createStub(RunVisionCheckService::class);
        $visionCheck->method('isModelVisionCapable')->willReturn(false);

        $toolbox = $this->validationToolbox(visionCheck: $visionCheck);

        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(false);
        $context = new ToolContext('run-nonvision', 1, 'call-nonvision', 'view_image', $token, 30);

        $execute = static function () use ($toolbox, $imagePath): ToolResult {
            return $toolbox->execute(new SymfonyToolCall('call-nonvision', 'view_image', ['path' => $imagePath]));
        };
        $result = $this->contextAccessor->with($context, $execute);
        $this->assertInstanceOf(ToolResult::class, $result);

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not support image input', $message);
        $this->assertStringContainsString('Switch to a vision-capable model', $message);
    }

    public function testVisionCapableModelPassesValidation(): void
    {
        $imagePath = $this->tmpDir.'/vision.png';
        $this->createPng1x1($imagePath);

        $visionCheck = $this->createStub(RunVisionCheckService::class);
        $visionCheck->method('isModelVisionCapable')->willReturn(true);

        // Valid image under a vision-capable model passes validation and
        // reaches the handler.
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(false);
        $context = new ToolContext('run-vision', 1, 'call-vision', 'view_image', $token, 30);

        $toolbox = $this->validationToolbox(visionCheck: $visionCheck);
        $execute = static function () use ($toolbox, $imagePath): ToolResult {
            return $toolbox->execute(new SymfonyToolCall('call-vision', 'view_image', ['path' => $imagePath]));
        };
        $result = $this->contextAccessor->with($context, $execute);
        $this->assertInstanceOf(ToolResult::class, $result);

        $this->assertIsArray($result->getResult());
        $this->assertSame('image/png', $result->getResult()['media_type']);
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

                ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $this->tmpDir.'/cancelled.png'));
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
                ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $imagePath));
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
        $tool = new ViewImageTool($toolRuntime);
        $registry = new ToolRegistry([$tool]);
        $toolbox = new RegistryBackedToolbox(
            $registry,
            new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
        );

        $tokenCancelledFirst = $this->createStub(CancellationTokenInterface::class);
        $tokenCancelledFirst->method('isCancellationRequested')->willReturn(false);

        $executor = ToolExecutor::fromSettings(
            settings: new ToolSettings(
                mode: 'sequential',
                maxParallelism: 1,
            ),
            resultStore: $resultStore,
            toolbox: $toolbox,
            contextAccessor: $contextAccessor,
        );

        $toolCall = new ToolCall(
            toolCallId: 'view_image_call_1',
            toolName: 'view_image',
            // Flat provider arguments for the DTO-typed tool.
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
        $this->assertFalse($toolResult->isError, 'Tool result should not be an error: '.($toolResult->content[0]['text'] ?? 'no content'));

        $contentText = $toolResult->content[0]['text'] ?? '';
        $this->assertJson($contentText);

        $parsed = json_decode($contentText, true);
        $this->assertIsArray($parsed);
        $this->assertSame('view_image', $parsed['type']);
        $this->assertSame('image/png', $parsed['media_type']);
        $this->assertSame(1, $parsed['width']);
        $this->assertSame(1, $parsed['height']);
        // Verify no base64 in the text content
        $this->assertArrayNotHasKey('base64', $parsed, 'Text content must not contain base64');
        $this->assertArrayNotHasKey('data_url', $parsed, 'Text content must not contain data_url');

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
        $this->assertCount(2, $agentMessage->content, 'AgentMessage should have text + image_ref content parts');
        $this->assertSame('text', $agentMessage->content[0]['type']);
        $this->assertSame('image_ref', $agentMessage->content[1]['type'], 'Second content part must be image_ref');
        $this->assertSame($imagePath, $agentMessage->content[1]['path']);
        $this->assertSame('image/png', $agentMessage->content[1]['media_type']);

        // 4. Run through AgentMessageConverter::toMessageBag()
        $converter = new AgentMessageConverter();
        $messageBag = $converter->toMessageBag([$agentMessage]);

        $messages = $messageBag->getMessages();
        // Should have 2 messages: ToolCallMessage + UserMessage (with Image)
        $this->assertCount(2, $messages, 'MessageBag should contain 2 messages (tool result + image attachment)');

        // First message: ToolCallMessage with text content
        $toolCallMsg = $messages[0];
        $this->assertSame('tool', $toolCallMsg->getRole()->value);
        $toolContent = $toolCallMsg->asText();
        $this->assertNotNull($toolContent);
        // Use non-slash substrings to avoid JSON encoding escaping issues
        $this->assertStringContainsString('media_type', $toolContent, 'Tool message should contain image metadata');
        $this->assertStringContainsString('view_image', $toolContent, 'Tool message should reference view_image');

        // Second message: UserMessage with real Image content
        $imageMsg = $messages[1];
        $this->assertSame('user', $imageMsg->getRole()->value);
        $this->assertInstanceOf(UserMessage::class, $imageMsg);

        // Verify the UserMessage contains an Image content object
        $this->assertTrue($imageMsg->hasImageContent(), 'UserMessage must have image content after conversion');

        // Find the Image content object in the message content parts
        $foundImage = null;
        foreach ($imageMsg->getContent() as $contentPart) {
            if ($contentPart instanceof Image) {
                $foundImage = $contentPart;
                break;
            }
        }
        $this->assertNotNull($foundImage, 'UserMessage should contain an Image content object');
        $this->assertSame('image/png', $foundImage->getFormat());
        // Verify it can produce a valid data URL (lazy-read works)
        $dataUrl = $foundImage->asDataUrl();
        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    public function testPipelineDoesNotPersistBase64InSerializedState(): void
    {
        // Verify that serializing the AgentMessage produced by the pipeline
        // does NOT contain base64 or data_url strings.

        $imagePath = $this->tmpDir.'/state_test.png';
        $this->createPng1x1($imagePath);

        $regularResult = ($this->viewImageTool)(new ViewImageArgumentsDTO(path: $imagePath));

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

        // Serialize to an array for the tool input fixture
        $serialized = $agentMessage->toArray();

        $serializedJson = json_encode($serialized);
        $this->assertIsString($serializedJson);

        // The serialized JSON must NOT contain base64 image data or data URLs
        $this->assertStringNotContainsString('base64,', $serializedJson, 'Serialized state must not contain base64 image data');
        $this->assertStringNotContainsString('data:image/', $serializedJson, 'Serialized state must not contain data URLs');

        // The serialized JSON SHOULD contain image_ref metadata
        $this->assertStringContainsString('image_ref', $serializedJson, 'Serialized state should contain image_ref type');
        // Use non-slash substrings to avoid JSON encoding escaping issues
        $this->assertStringContainsString('image_ref', $serializedJson, 'Serialized state should contain image_ref');
        $this->assertStringContainsString('png', $serializedJson, 'Serialized state should contain file extension');
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
        $this->assertCount(0, $imageRefParts, 'Non-image tool should not have image_ref content parts');
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

        $this->assertCount(3, $messages);
        $this->assertSame('tool', $messages[0]->getRole()->value);
        $this->assertSame('tool', $messages[1]->getRole()->value);
        $this->assertSame('user', $messages[2]->getRole()->value);
        $this->assertInstanceOf(UserMessage::class, $messages[2]);
        $this->assertTrue($messages[2]->hasImageContent());
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
        $this->assertCount(2, $messages);

        // Second message should be a text-only user message, not an Image
        $secondMsg = $messages[1];
        $this->assertSame('user', $secondMsg->getRole()->value);
        // UserMessage::getContent() returns ContentInterface[]; use asText() for string
        $secondText = $secondMsg instanceof UserMessage
            ? ($secondMsg->asText() ?? '')
            : ($secondMsg->asText() ?? '');
        $this->assertStringContainsString('deleted', $secondText);
        // When file is missing, hasImageContent should be false
        if ($secondMsg instanceof UserMessage) {
            $this->assertFalse($secondMsg->hasImageContent(), 'Deleted file should not produce image content');
        }
    }

    /* ── ImageGatingConvertHook tests ── */

    public function testImageGatingHookStripsImageRefForNonVisionModel(): void
    {
        $checker = $this->createStub(ImageCapabilityCheckerInterface::class);
        $checker->method('supportsImages')->willReturn(false);

        $converter = new AgentMessageConverter();
        $hook = new \Ineersa\CodingAgent\Tool\ImageProcessing\ImageGatingConvertHook($checker, $converter);

        $imagePath = $this->tmpDir.'/gating_nonvision.png';
        $this->createPng1x1($imagePath);

        $agentMessage = new AgentMessage(
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
            toolCallId: 'call_nonvision',
            toolName: 'view_image',
            details: [],
        );

        $messageBag = $hook->convertToLlm([$agentMessage], null, 'some/non-vision-model');
        $messages = $messageBag->getMessages();

        // Should have 1 message: the ToolCallMessage with text placeholder
        // (no synthetic UserMessage since image_ref was stripped by the hook)
        $this->assertCount(1, $messages);

        $msg = $messages[0];
        $this->assertSame('tool', $msg->getRole()->value);

        $msgText = $msg->asText();
        $this->assertNotNull($msgText);
        $this->assertStringContainsString('does not support images', $msgText);
    }

    public function testImageGatingHookPassesThroughForVisionModel(): void
    {
        $checker = $this->createStub(ImageCapabilityCheckerInterface::class);
        $checker->method('supportsImages')->willReturn(true);

        $converter = new AgentMessageConverter();
        $hook = new \Ineersa\CodingAgent\Tool\ImageProcessing\ImageGatingConvertHook($checker, $converter);

        $imagePath = $this->tmpDir.'/gating_vision.png';
        $this->createPng1x1($imagePath);

        $agentMessage = new AgentMessage(
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
            toolCallId: 'call_vision',
            toolName: 'view_image',
            details: [],
        );

        $messageBag = $hook->convertToLlm([$agentMessage], null, 'some/vision-model');
        $messages = $messageBag->getMessages();

        $this->assertCount(2, $messages);

        $secondMsg = $messages[1];
        $this->assertInstanceOf(UserMessage::class, $secondMsg);
        $this->assertTrue($secondMsg->hasImageContent(), 'Vision model must receive Image content');
    }

    public function testImageGatingHookStripsImageRefForEmptyModelName(): void
    {
        $checker = $this->createStub(ImageCapabilityCheckerInterface::class);
        // Empty model name short-circuits to false before checker is called.
        $checker->method('supportsImages')->willReturn(true);

        $converter = new AgentMessageConverter();
        $hook = new \Ineersa\CodingAgent\Tool\ImageProcessing\ImageGatingConvertHook($checker, $converter);

        $imagePath = $this->tmpDir.'/gating_empty_model.png';
        $this->createPng1x1($imagePath);

        $agentMessage = new AgentMessage(
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
            toolCallId: 'call_empty_model',
            toolName: 'view_image',
            details: [],
        );

        $messageBag = $hook->convertToLlm([$agentMessage], null, '');
        $messages = $messageBag->getMessages();

        // Should have 1 message: the ToolCallMessage with text placeholder
        $this->assertCount(1, $messages);

        $msg = $messages[0];
        $this->assertSame('tool', $msg->getRole()->value);
    }

    public function testImageGatingHookPreservesTextContentParts(): void
    {
        // Verify the hook doesn't strip text-only content parts from messages
        // that have no image_ref.
        $checker = $this->createStub(ImageCapabilityCheckerInterface::class);
        $checker->method('supportsImages')->willReturn(false);

        $converter = new AgentMessageConverter();
        $hook = new \Ineersa\CodingAgent\Tool\ImageProcessing\ImageGatingConvertHook($checker, $converter);

        $agentMessage = new AgentMessage(
            role: 'tool',
            content: [
                ['type' => 'text', 'text' => 'File written successfully (42 bytes)'],
            ],
            toolCallId: 'call_write',
            toolName: 'write_file',
            details: [],
        );

        $messageBag = $hook->convertToLlm([$agentMessage], null, 'some/non-vision-model');
        $messages = $messageBag->getMessages();

        // Should have exactly 1 message (just the tool call, no synthetic image message)
        $this->assertCount(1, $messages);
        $this->assertSame('tool', $messages[0]->getRole()->value);
    }

    /* ── Vision capability check throws clear error ── */

    public function testVisionCheckSkippedWhenNoVisionCheckService(): void
    {
        $imagePath = $this->tmpDir.'/test-no-checker.png';
        $this->createPng1x1($imagePath);

        $result = $this->validationToolbox()->execute(new SymfonyToolCall('call-view', 'view_image', ['path' => $imagePath]));

        $this->assertIsArray($result->getResult());
        $this->assertSame('image/png', $result->getResult()['media_type'], 'Tool should succeed when no vision check service is configured');
    }

    /* ── Unsupported file type rejection ── */

    /**
     * Production-shaped invalid-argument path: registry → native resolver →
     * ValidateToolCallArgumentsListener with a ViewImageTargetValidator bound
     * to the given config/vision service → FaultTolerantToolbox.
     */
    private function validationToolbox(?ImageToolConfig $config = null, ?RunVisionCheckService $visionCheck = null): FaultTolerantToolbox
    {
        return ToolValidationHarness::toolbox($this->viewImageTool, [
            ViewImageTargetValidator::class => new ViewImageTargetValidator(
                $config ?? $this->imageConfig,
                $this->contextAccessor,
                $visionCheck,
            ),
        ]);
    }

    private function validateDto(object $dto): array
    {
        $validator = (new ValidatorBuilder())
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                ViewImageTargetValidator::class => new ViewImageTargetValidator($this->imageConfig, $this->contextAccessor),
            ]))
            ->getValidator();

        return iterator_to_array($validator->validate($dto));
    }

    /* ── helper: create tiny test images ── */

    private function createPng1x1(string $path): void
    {
        $img = imagecreatetruecolor(1, 1);
        imagepng($img, $path);
        // imagedestroy is no-op since PHP 8.0, removed
    }

    private function createGif1x1(string $path): void
    {
        $img = imagecreatetruecolor(1, 1);
        imagegif($img, $path);
        // imagedestroy is no-op since PHP 8.0, removed
    }

    private function createJpeg1x1(string $path): void
    {
        $img = imagecreatetruecolor(1, 1);
        imagejpeg($img, $path);
        // imagedestroy is no-op since PHP 8.0, removed
    }

    private function createWebp1x1(string $path): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available.');
        }

        $img = imagecreatetruecolor(1, 1);
        imagewebp($img, $path);
        // imagedestroy is no-op since PHP 8.0, removed
    }

    /**
     * Write raw bytes to a fixture file.
     */
    private function writeFixture(string $path, string $content): void
    {
        file_put_contents($path, $content);
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
