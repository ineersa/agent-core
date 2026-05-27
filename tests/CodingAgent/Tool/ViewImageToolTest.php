<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\CodingAgent\Config\ToolSettings;
use Ineersa\CodingAgent\Tool\ViewImageTool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\ViewImageTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 * @covers \Ineersa\CodingAgent\Config\ImageToolConfig
 * @covers \Ineersa\AgentCore\Application\Handler\ToolExecutor
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter
 */
final class ViewImageToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private ViewImageTool $viewImageTool;
    private ImageToolConfig $imageConfig;
    private OutputCap $outputCap;
    private string $tmpDir;
    private string $outputCapDir;

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

        $this->outputCapDir = $this->tmpDir.'/output-cap';
        mkdir($this->outputCapDir, 0750, recursive: true);
        $outputCapConfig = new OutputCapConfig(
            storageDir: $this->outputCapDir,
            defaultCap: 20000,
            docCap: 50000,
            retentionSeconds: 86400,
            sessionPrefix: 'test',
        );
        $this->outputCap = new OutputCap($outputCapConfig);

        $this->viewImageTool = new ViewImageTool($this->toolRuntime, $this->imageConfig, $this->outputCap);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    private function createOutputCap(int $defaultCap = 20000): OutputCap
    {
        $dir = $this->tmpDir.'/output-cap-'.bin2hex(random_bytes(4));
        mkdir($dir, 0750, true);

        return new OutputCap(new OutputCapConfig(
            storageDir: $dir,
            defaultCap: $defaultCap,
            docCap: $defaultCap,
            retentionSeconds: 3600,
            sessionPrefix: 'test',
        ));
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

    /* ── __invoke() success tests ── */

    public function testViewPngImageReturnsMetadata(): void
    {
        $imagePath = $this->tmpDir.'/test.png';
        $this->createPng1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertIsArray($result);
        self::assertSame('view_image', $result['type']);
        self::assertSame('image/png', $result['media_type']);
        self::assertStringContainsString('data:image/png;base64,', $result['data_url']);
        self::assertNotEmpty($result['base64']);
        self::assertGreaterThan(0, $result['bytes']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertSame($imagePath, $result['path']);

        // Verify the base64 decodes back to valid PNG
        $decoded = base64_decode($result['base64'], true);
        self::assertNotFalse($decoded);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $decoded);
    }

    public function testViewGifImageReturnsMetadata(): void
    {
        $imagePath = $this->tmpDir.'/test.gif';
        $this->createGif1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertIsArray($result);
        self::assertSame('image/gif', $result['media_type']);
        self::assertStringContainsString('data:image/gif;base64,', $result['data_url']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
    }

    public function testViewJpegImageReturnsMetadata(): void
    {
        $imagePath = $this->tmpDir.'/test.jpg';
        $this->createJpeg1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertIsArray($result);
        self::assertSame('image/jpeg', $result['media_type']);
        self::assertStringContainsString('data:image/jpeg;base64,', $result['data_url']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
    }

    public function testViewWebpImageReturnsMetadata(): void
    {
        if (!\function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available.');
        }

        $imagePath = $this->tmpDir.'/test.webp';
        $this->createWebp1x1($imagePath);

        $result = ($this->viewImageTool)(['path' => $imagePath]);

        self::assertIsArray($result);
        self::assertSame('image/webp', $result['media_type']);
        self::assertStringContainsString('data:image/webp;base64,', $result['data_url']);
    }

    public function testViewImageWithRelativePathResolvesAgainstCwd(): void
    {
        $filename = 'view_image_test_relative_'.\bin2hex(random_bytes(4)).'.png';
        $relativePath = $this->tmpDir.'/'.$filename;
        $this->createPng1x1($relativePath);

        // We need a path relative to CWD
        $cwd = getcwd();
        $relative = $this->relativePath($cwd, $relativePath);

        $result = ($this->viewImageTool)(['path' => $relative]);

        self::assertSame('image/png', $result['media_type']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
    }

    /* ── Magic-byte detection tests (not extension-only) ── */

    public function testDetectsPngByMagicBytesNotExtension(): void
    {
        // Rename a PNG to .gif — detection must use magic bytes
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
        $tool = new ViewImageTool($this->toolRuntime, $smallConfig, $this->createOutputCap());

        // Create a 1x1 PNG (~110-175 bytes depending on palette) larger than 50 bytes
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
        $tool = new ViewImageTool($this->toolRuntime, $largeConfig, $this->createOutputCap());

        $imagePath = $this->tmpDir.'/ok.png';
        $this->createPng1x1($imagePath);

        $result = $tool(['path' => $imagePath]);

        self::assertSame('image/png', $result['media_type']);
    }

    /* ── Dimension enforcement ── */

    public function testRejectsImageExceedingMaxWidth(): void
    {
        $smallConfig = new ImageToolConfig(maxBytes: 10_485_760, maxWidth: 2, maxHeight: 2000);
        $tool = new ViewImageTool($this->toolRuntime, $smallConfig, $this->createOutputCap());

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
        $tool = new ViewImageTool($this->toolRuntime, $smallConfig, $this->createOutputCap());

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

    /* ── Conversion survival through ToolExecutor and AgentMessageConverter ── */

    public function testResultSurvivesToolExecutorAndAgentMessageConverter(): void
    {
        // This test proves the full pipeline:
        //   ViewImageTool handler → RegistryBackedToolbox → ToolExecutor → AgentMessageConverter
        // and asserts that base64/media_type/data_url appear in the final MessageBag
        // delivered to the LLM platform.

        $imagePath = $this->tmpDir.'/pipeline.png';
        $this->createPng1x1($imagePath);

        // Wire up real objects
        $resultStore = new ToolExecutionResultStore();

        $contextAccessor = new StackToolExecutionContextAccessor();
        $toolRuntime = new ToolRuntime($contextAccessor);
        $tool = new ViewImageTool($toolRuntime, $this->imageConfig, $this->createOutputCap(100_000));

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

        // 1. Assert ToolResult content contains the image data as JSON text
        self::assertFalse($toolResult->isError, 'Tool result should not be an error: '.($toolResult->content[0]['text'] ?? 'no content'));

        $contentJson = $toolResult->content[0]['text'] ?? '';
        self::assertJson($contentJson);

        $parsed = json_decode($contentJson, true);
        self::assertIsArray($parsed);
        self::assertSame('view_image', $parsed['type']);
        self::assertSame('image/png', $parsed['media_type']);
        self::assertNotEmpty($parsed['base64']);
        self::assertStringContainsString('data:image/png;base64,', $parsed['data_url']);
        self::assertSame(1, $parsed['width']);
        self::assertSame(1, $parsed['height']);

        // 2. Assert details also contain the raw result array
        self::assertIsArray($toolResult->details);
        self::assertSame('view_image', $toolResult->details['raw_result']['type']);
        self::assertSame('image/png', $toolResult->details['raw_result']['media_type']);

        // 3. Build an AgentMessage from the ToolResult and run through AgentMessageConverter
        $agentMessage = new AgentMessage(
            role: 'tool',
            content: $toolResult->content,
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            details: $toolResult->details,
            isError: $toolResult->isError,
        );

        $converter = new AgentMessageConverter();
        $messageBag = $converter->toMessageBag([$agentMessage]);

        // The MessageBag should contain one message with tool call content
        $messages = $messageBag->getMessages();
        self::assertCount(1, $messages);

        $message = $messages[0];
        $messageStr = $message->getContent();

        // The content string should include media_type, base64, and data_url
        self::assertStringContainsString('image/png', $messageStr);
        self::assertStringContainsString('base64', $messageStr);
        self::assertStringContainsString('data_url', $messageStr);
        self::assertStringContainsString('data:image/png;base64,', $messageStr);
    }

    /* ── OutputCap capping test ── */

    public function testLargeResultCappedViaOutputCap(): void
    {
        // Create an OutputCap with a tiny cap so even a 1x1 PNG gets capped
        $tinyCap = $this->createOutputCap(10);

        $tool = new ViewImageTool($this->toolRuntime, $this->imageConfig, $tinyCap);

        $imagePath = $this->tmpDir.'/capped.png';
        $this->createPng1x1($imagePath);

        $result = $tool(['path' => $imagePath]);

        // Should return compact result without base64/data_url
        self::assertArrayNotHasKey('base64', $result, 'Capped result should not contain base64');
        self::assertArrayNotHasKey('data_url', $result, 'Capped result should not contain data_url');
        self::assertArrayHasKey('output_cap_path', $result, 'Capped result should contain output_cap_path');
        self::assertArrayHasKey('note', $result, 'Capped result should contain note');

        // Verify the output cap file exists and contains the full data
        self::assertFileExists($result['output_cap_path']);
        $savedJson = file_get_contents($result['output_cap_path']);
        $savedData = json_decode($savedJson, true);
        self::assertIsArray($savedData);
        self::assertSame('view_image', $savedData['type']);
        self::assertSame('image/png', $savedData['media_type']);
        self::assertNotEmpty($savedData['base64']);
        self::assertStringContainsString('data:image/png;base64,', $savedData['data_url']);
        self::assertSame(1, $savedData['width']);
        self::assertSame(1, $savedData['height']);

        // Compact result retains key metadata
        self::assertSame('image/png', $result['media_type']);
        self::assertSame(1, $result['width']);
        self::assertSame(1, $result['height']);
        self::assertGreaterThan(0, $result['bytes']);
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

    /**
     * Compute a relative path from $from to $to.
     */
    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', rtrim($from, '/'));
        $toParts = explode('/', rtrim($to, '/'));

        // Remove common prefix
        $i = 0;
        while ($i < \count($fromParts) && $i < \count($toParts) && $fromParts[$i] === $toParts[$i]) {
            ++$i;
        }

        // Add '..' for remaining from parts
        $relative = [];
        for ($j = $i; $j < \count($fromParts); ++$j) {
            $relative[] = '..';
        }

        // Add remaining to parts
        for ($j = $i; $j < \count($toParts); ++$j) {
            $relative[] = $toParts[$j];
        }

        return implode('/', $relative);
    }
}
