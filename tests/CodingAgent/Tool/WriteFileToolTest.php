<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\CodingAgent\Tool\WriteFileTool;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\WriteFileTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 */
final class WriteFileToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private string $tmpDir;
    private WriteFileTool $writeFileTool;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_write_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->writeFileTool = new WriteFileTool($this->toolRuntime);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsWrite(): void
    {
        $definition = $this->writeFileTool->definition();

        self::assertSame('write', $definition->name);
    }

    public function testDefinitionHasDescription(): void
    {
        $definition = $this->writeFileTool->definition();

        self::assertNotEmpty($definition->description);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->writeFileTool->definition();

        self::assertTrue(method_exists($definition->handler, '__invoke'));
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->writeFileTool->definition();

        self::assertNotEmpty($definition->promptLine);
        self::assertStringContainsString('write', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->writeFileTool->definition();

        self::assertNotEmpty($definition->promptGuidelines);
    }

    public function testDefinitionJsonSchemaHasPathAndContent(): void
    {
        $definition = $this->writeFileTool->definition();
        $schema = $definition->parametersJsonSchema;

        self::assertArrayHasKey('type', $schema);
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('path', $schema['properties']);
        self::assertArrayHasKey('content', $schema['properties']);
        self::assertArrayHasKey('required', $schema);
        self::assertContains('path', $schema['required']);
        self::assertContains('content', $schema['required']);
        self::assertArrayHasKey('additionalProperties', $schema);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionImplementsHatfieldToolProviderInterface(): void
    {
        self::assertTrue(method_exists($this->writeFileTool, 'definition'));
    }

    /* ── ToolRegistry integration test ── */

    public function testRegistryExposesWriteTool(): void
    {
        $registry = new ToolRegistry([$this->writeFileTool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(static fn ($t) => $t->getName(), $tools);

        self::assertContains('write', $toolNames);
    }

    /* ── __invoke() success tests ── */

    public function testWriteCreatesNewFile(): void
    {
        $targetPath = $this->tmpDir.'/new_file.txt';
        $content = 'Hello, World!';

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        self::assertStringContainsString('Successfully', $result);
        self::assertStringContainsString('new_file.txt', $result);
        self::assertFileExists($targetPath);
        // Non-empty content without trailing newline is normalized: \n appended
        self::assertSame("Hello, World!\n", file_get_contents($targetPath));
    }

    public function testWriteCreatesNestedDirectories(): void
    {
        $targetPath = $this->tmpDir.'/nested/subdir/deep/file.txt';
        $content = 'Nested content';

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        self::assertStringContainsString('Successfully', $result);
        self::assertFileExists($targetPath);
        // Non-empty content without trailing newline is normalized
        self::assertSame("Nested content\n", file_get_contents($targetPath));
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $targetPath = $this->tmpDir.'/overwrite.txt';
        file_put_contents($targetPath, 'Old content');

        $newContent = 'New content replacing the old one.';
        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $newContent]);

        self::assertStringContainsString('Successfully', $result);
        // Non-empty content without trailing newline is normalized
        self::assertSame("New content replacing the old one.\n", file_get_contents($targetPath));
    }

    public function testWriteReturnsByteCount(): void
    {
        $targetPath = $this->tmpDir.'/bytecount.txt';
        $content = str_repeat('A', 1000);

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        // Non-empty content without trailing newline: one extra byte for \n
        self::assertStringContainsString('1001 bytes', $result);
    }

    public function testWriteEmptyContent(): void
    {
        $targetPath = $this->tmpDir.'/empty.txt';

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => '']);

        self::assertStringContainsString('0 bytes', $result);
        self::assertFileExists($targetPath);
        self::assertSame('', file_get_contents($targetPath));
    }

    public function testWriteWithRelativePathResolvesAgainstCwd(): void
    {
        $relativePath = 'write_test_relative_'.bin2hex(random_bytes(4)).'.txt';
        $content = 'Relative path test.';

        try {
            $result = ($this->writeFileTool)(['path' => $relativePath, 'content' => $content]);

            $cwd = getcwd();
            self::assertFileExists($cwd.'/'.$relativePath);
            self::assertStringContainsString($cwd.'/'.$relativePath, $result);
            // Non-empty content without trailing newline is normalized
            self::assertSame("Relative path test.\n", file_get_contents($cwd.'/'.$relativePath));
        } finally {
            $cwd = getcwd();
            $fullPath = $cwd.'/'.$relativePath;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    /* ── __invoke() argument validation tests ── */

    public function testWriteThrowsOnMissingPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->writeFileTool)(['content' => 'some content']);
    }

    public function testWriteThrowsOnEmptyPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->writeFileTool)(['path' => '', 'content' => 'content']);
    }

    public function testWriteThrowsOnNonStringPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->writeFileTool)(['path' => 42, 'content' => 'content']);
    }

    public function testWriteThrowsOnMissingContent(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"content" argument is required');

        ($this->writeFileTool)(['path' => $this->tmpDir.'/test.txt']);
    }

    public function testWriteThrowsOnNonStringContent(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"content" argument is required');

        ($this->writeFileTool)(['path' => $this->tmpDir.'/test.txt', 'content' => ['not', 'a', 'string']]);
    }

    public function testWriteThrowsWhenParentExistsAsFile(): void
    {
        $existingFile = $this->tmpDir.'/existing_file.txt';
        file_put_contents($existingFile, 'I am a file, not a directory.');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Failed to write file');

        ($this->writeFileTool)(['path' => $existingFile.'/child.txt', 'content' => 'cannot create']);
    }

    /* ── Trailing newline normalization tests ── */

    public function testWriteAppendsNewlineToNonEmptyContent(): void
    {
        $targetPath = $this->tmpDir.'/newline_added.txt';
        $content = 'No trailing newline';

        ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        self::assertSame("No trailing newline\n", file_get_contents($targetPath));
    }

    public function testWriteDoesNotDoubleNewlineWhenAlreadyPresent(): void
    {
        $targetPath = $this->tmpDir.'/no_double_newline.txt';
        $content = "Has trailing newline\n";

        ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        self::assertSame("Has trailing newline\n", file_get_contents($targetPath));
    }

    public function testWriteEmptyContentRemainsEmpty(): void
    {
        $targetPath = $this->tmpDir.'/empty_stays_empty.txt';

        ($this->writeFileTool)(['path' => $targetPath, 'content' => '']);

        self::assertSame('', file_get_contents($targetPath));
    }

    public function testWriteDoesNotModifyCrlfEnding(): void
    {
        $targetPath = $this->tmpDir.'/crlf_content.txt';
        $content = "line1\r\n";

        ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        // CRLF content already ends with \n, so no modification
        self::assertSame("line1\r\n", file_get_contents($targetPath));
    }

    public function testWriteCancelledBeforeExecutionThrows(): void
    {
        $token = $this->createToken(true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function (): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');

                ($this->writeFileTool)([
                    'path' => $this->tmpDir.'/cancelled.txt',
                    'content' => 'Should not be written.',
                ]);
            },
        );

        // The file should NOT exist because cancellation happened before execution
        self::assertFileDoesNotExist($this->tmpDir.'/cancelled.txt');
    }

    public function testWriteCancelledAfterExecutionThrows(): void
    {
        $targetPath = $this->tmpDir.'/stale.txt';
        $token = $this->createMock(CancellationTokenInterface::class);
        $token->expects(self::exactly(2))
            ->method('isCancellationRequested')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function () use ($targetPath): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('stale due to run cancellation');

                ($this->writeFileTool)([
                    'path' => $targetPath,
                    'content' => 'This will be written but reported as stale.',
                ]);
            },
        );

        // The file IS written because cancellation happened after the write
        // but the toll runtime still throws to prevent the stale result from
        // reaching the LLM.
        self::assertFileExists($targetPath);
    }

    /* ── helpers ── */

    private function createToken(bool $cancelled): CancellationTokenInterface
    {
        $token = self::createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn($cancelled);

        return $token;
    }

    private function contextWithToken(CancellationTokenInterface $token): ToolContext
    {
        return new ToolContext(
            runId: 'write_test_run',
            turnNo: 1,
            toolCallId: 'write_call_1',
            toolName: 'write',
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
}
