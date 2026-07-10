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

        $this->assertSame('write', $definition->name);
    }

    public function testDefinitionHasDescription(): void
    {
        $definition = $this->writeFileTool->definition();

        $this->assertNotEmpty($definition->description);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->writeFileTool->definition();

        $this->assertTrue(method_exists($definition->handler, '__invoke'));
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->writeFileTool->definition();

        $this->assertNotEmpty($definition->promptLine);
        $this->assertStringContainsString('write', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->writeFileTool->definition();

        $this->assertNotEmpty($definition->promptGuidelines);
    }

    public function testDefinitionJsonSchemaHasPathAndContent(): void
    {
        $definition = $this->writeFileTool->definition();
        $schema = $definition->parametersJsonSchema;

        $this->assertArrayHasKey('type', $schema);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('content', $schema['properties']);
        $this->assertArrayHasKey('required', $schema);
        $this->assertContains('path', $schema['required']);
        $this->assertContains('content', $schema['required']);
        $this->assertArrayHasKey('additionalProperties', $schema);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionImplementsHatfieldToolProviderInterface(): void
    {
        $this->assertTrue(method_exists($this->writeFileTool, 'definition'));
    }

    /* ── ToolRegistry integration test ── */

    public function testRegistryExposesWriteTool(): void
    {
        $registry = new ToolRegistry([$this->writeFileTool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(static fn ($t) => $t->getName(), $tools);

        $this->assertContains('write', $toolNames);
    }

    /* ── __invoke() success tests ── */

    public function testWriteCreatesNewFile(): void
    {
        $targetPath = $this->tmpDir.'/new_file.txt';
        $content = 'Hello, World!';

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        $this->assertStringContainsString('Successfully', $result);
        $this->assertStringContainsString('new_file.txt', $result);
        $this->assertFileExists($targetPath);
        // Non-empty content without trailing newline is normalized: \n appended
        $this->assertSame("Hello, World!\n", file_get_contents($targetPath));
    }

    public function testWriteCreatesNestedDirectories(): void
    {
        $targetPath = $this->tmpDir.'/nested/subdir/deep/file.txt';
        $content = 'Nested content';

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        $this->assertStringContainsString('Successfully', $result);
        $this->assertFileExists($targetPath);
        // Non-empty content without trailing newline is normalized
        $this->assertSame("Nested content\n", file_get_contents($targetPath));
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $targetPath = $this->tmpDir.'/overwrite.txt';
        file_put_contents($targetPath, 'Old content');

        $newContent = 'New content replacing the old one.';
        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $newContent]);

        $this->assertStringContainsString('Successfully', $result);
        // Non-empty content without trailing newline is normalized
        $this->assertSame("New content replacing the old one.\n", file_get_contents($targetPath));
    }

    public function testWriteReturnsByteCount(): void
    {
        $targetPath = $this->tmpDir.'/bytecount.txt';
        $content = str_repeat('A', 1000);

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        // Non-empty content without trailing newline: one extra byte for \n
        $this->assertStringContainsString('1001 bytes', $result);
    }

    public function testWriteEmptyContent(): void
    {
        $targetPath = $this->tmpDir.'/empty.txt';

        $result = ($this->writeFileTool)(['path' => $targetPath, 'content' => '']);

        $this->assertStringContainsString('0 bytes', $result);
        $this->assertFileExists($targetPath);
        $this->assertSame('', file_get_contents($targetPath));
    }

    public function testWriteWithRelativePathResolvesAgainstCwd(): void
    {
        $relativePath = 'write_test_relative_'.bin2hex(random_bytes(4)).'.txt';
        $content = 'Relative path test.';

        try {
            $result = ($this->writeFileTool)(['path' => $relativePath, 'content' => $content]);

            $cwd = getcwd();
            $this->assertFileExists($cwd.'/'.$relativePath);
            $this->assertStringContainsString($relativePath, $result);
            $this->assertStringNotContainsString($cwd, $result);
            // Non-empty content without trailing newline is normalized
            $this->assertSame("Relative path test.\n", file_get_contents($cwd.'/'.$relativePath));
        } finally {
            $cwd = getcwd();
            $fullPath = $cwd.'/'.$relativePath;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }

    /**
     * Regression: llm-real write-file post-tool cache keys must not embed resolved absolute temp cwd.
     */
    public function testWriteSuccessReturnsCallerSuppliedRelativePath(): void
    {
        $workDir = $this->tmpDir.'/write_cwd_'.bin2hex(random_bytes(4));
        mkdir($workDir, 0750, recursive: true);
        $callerPath = './test-write.txt';
        $previousCwd = getcwd();

        try {
            $this->assertTrue(chdir($workDir));

            $result = ($this->writeFileTool)(['path' => $callerPath, 'content' => 'hello world']);

            $this->assertFileExists($workDir.'/test-write.txt');
            $this->assertSame("hello world\n", file_get_contents($workDir.'/test-write.txt'));
            $this->assertSame('Successfully wrote 12 bytes to ./test-write.txt', $result);
            $this->assertStringNotContainsString($workDir, $result);
        } finally {
            if (false !== $previousCwd) {
                chdir($previousCwd);
            }
            if (is_file($workDir.'/test-write.txt')) {
                unlink($workDir.'/test-write.txt');
            }
            if (is_dir($workDir)) {
                rmdir($workDir);
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

        $this->assertSame("No trailing newline\n", file_get_contents($targetPath));
    }

    public function testWriteDoesNotDoubleNewlineWhenAlreadyPresent(): void
    {
        $targetPath = $this->tmpDir.'/no_double_newline.txt';
        $content = "Has trailing newline\n";

        ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        $this->assertSame("Has trailing newline\n", file_get_contents($targetPath));
    }

    public function testWriteEmptyContentRemainsEmpty(): void
    {
        $targetPath = $this->tmpDir.'/empty_stays_empty.txt';

        ($this->writeFileTool)(['path' => $targetPath, 'content' => '']);

        $this->assertSame('', file_get_contents($targetPath));
    }

    public function testWriteDoesNotModifyCrlfEnding(): void
    {
        $targetPath = $this->tmpDir.'/crlf_content.txt';
        $content = "line1\r\n";

        ($this->writeFileTool)(['path' => $targetPath, 'content' => $content]);

        // CRLF content already ends with \n, so no modification
        $this->assertSame("line1\r\n", file_get_contents($targetPath));
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
        $this->assertFileDoesNotExist($this->tmpDir.'/cancelled.txt');
    }

    public function testWriteCancelledAfterExecutionThrows(): void
    {
        $targetPath = $this->tmpDir.'/stale.txt';
        $token = $this->createMock(CancellationTokenInterface::class);
        $token->expects($this->exactly(2))
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
        $this->assertFileExists($targetPath);
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
