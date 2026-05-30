<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\ReadFileTool;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\ReadFileTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 */
final class ReadFileToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private OutputCap $outputCap;
    private string $tmpDir;
    private ReadFileTool $readFileTool;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_read_test_'.\bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        // High cap so tests don't trigger output capping by default
        $capConfig = new OutputCapConfig(
            storageDir: $this->tmpDir.'/output-cap',
            defaultCap: 1_000_000,
            docCap: 1_000_000,
        );
        $this->outputCap = new OutputCap($capConfig);

        $this->readFileTool = new ReadFileTool($this->toolRuntime, $this->outputCap);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsRead(): void
    {
        $definition = $this->readFileTool->definition();

        self::assertSame('read', $definition->name);
    }

    public function testDefinitionHasDescription(): void
    {
        $definition = $this->readFileTool->definition();

        self::assertNotEmpty($definition->description);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->readFileTool->definition();

        self::assertTrue(method_exists($definition->handler, '__invoke'));
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->readFileTool->definition();

        self::assertNotEmpty($definition->promptLine);
        self::assertStringContainsString('read', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->readFileTool->definition();

        self::assertNotEmpty($definition->promptGuidelines);
    }

    public function testDefinitionJsonSchemaHasPathOffsetLimit(): void
    {
        $definition = $this->readFileTool->definition();
        $schema = $definition->parametersJsonSchema;

        self::assertArrayHasKey('type', $schema);
        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('properties', $schema);
        self::assertArrayHasKey('path', $schema['properties']);
        self::assertArrayHasKey('offset', $schema['properties']);
        self::assertArrayHasKey('limit', $schema['properties']);
        self::assertArrayHasKey('required', $schema);
        self::assertContains('path', $schema['required']);
        self::assertArrayHasKey('additionalProperties', $schema);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionImplementsHatfieldToolProviderInterface(): void
    {
        self::assertTrue(method_exists($this->readFileTool, 'definition'));
    }

    public function testDefinitionExecutionModeIsSequential(): void
    {
        $definition = $this->readFileTool->definition();

        self::assertSame('sequential', $definition->executionMode->value);
    }

    /* ── ToolRegistry integration test ── */

    public function testRegistryExposesReadTool(): void
    {
        $registry = new ToolRegistry([$this->readFileTool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(static fn ($t) => $t->getName(), $tools);

        self::assertContains('read', $toolNames);
    }

    /* ── __invoke() success tests ── */

    public function testFullReadShowsCatNumbering(): void
    {
        $targetPath = $this->tmpDir.'/numbered.txt';
        $lines = ["line one", "line two", "line three", "line four", "line five"];
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        $result = ($this->readFileTool)(['path' => $targetPath]);

        // cat -n format: right-padded line number + tab + content
        self::assertStringContainsString("     1\tline one", $result);
        self::assertStringContainsString("     2\tline two", $result);
        self::assertStringContainsString("     3\tline three", $result);
        self::assertStringContainsString("     4\tline four", $result);
        self::assertStringContainsString("     5\tline five", $result);
    }

    public function testReadWithOffsetPreservesOriginalLineNumbers(): void
    {
        $targetPath = $this->tmpDir.'/offset_preserve.txt';
        $lines = [];
        for ($i = 1; $i <= 20; ++$i) {
            $lines[] = "line {$i}";
        }
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        // Read from line 10, get lines 10-14
        $result = ($this->readFileTool)(['path' => $targetPath, 'offset' => 10, 'limit' => 5]);

        self::assertStringContainsString("    10\tline 10", $result);
        self::assertStringContainsString("    11\tline 11", $result);
        self::assertStringContainsString("    12\tline 12", $result);
        self::assertStringContainsString("    13\tline 13", $result);
        self::assertStringContainsString("    14\tline 14", $result);
        // Ensure lines outside the range are NOT present
        self::assertStringNotContainsString("     9\tline 9", $result);
        self::assertStringNotContainsString("    15\tline 15", $result);
    }

    public function testReadWithOffsetOnly(): void
    {
        $targetPath = $this->tmpDir.'/offset_only.txt';
        $lines = [];
        for ($i = 1; $i <= 10; ++$i) {
            $lines[] = "line {$i}";
        }
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        // Read from line 8 to end
        $result = ($this->readFileTool)(['path' => $targetPath, 'offset' => 8]);

        self::assertStringContainsString("     8\tline 8", $result);
        self::assertStringContainsString("     9\tline 9", $result);
        self::assertStringContainsString("    10\tline 10", $result);
        self::assertStringNotContainsString("     7\tline 7", $result);
    }

    public function testReadWithLimitOnly(): void
    {
        $targetPath = $this->tmpDir.'/limit_only.txt';
        file_put_contents($targetPath, "a\nb\nc\nd\ne\n");

        $result = ($this->readFileTool)(['path' => $targetPath, 'limit' => 3]);

        self::assertStringContainsString("     1\ta", $result);
        self::assertStringContainsString("     2\tb", $result);
        self::assertStringContainsString("     3\tc", $result);
        self::assertStringNotContainsString("     4\td", $result);
    }

    public function testReadOffsetPastEofThrows(): void
    {
        $targetPath = $this->tmpDir.'/few_lines.txt';
        file_put_contents($targetPath, "line one\nline two\nline three\n");

        try {
            ($this->readFileTool)(['path' => $targetPath, 'offset' => 10]);
            self::fail('Expected ToolCallException was not thrown.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('offset 10 exceeds file length', $e->getMessage());
            self::assertStringContainsString('3 lines', $e->getMessage());
        }
    }

    public function testReadEmptyFile(): void
    {
        $targetPath = $this->tmpDir.'/empty.txt';
        file_put_contents($targetPath, '');

        $result = ($this->readFileTool)(['path' => $targetPath]);

        self::assertSame('', $result);
    }

    public function testReadFileWithSingleLine(): void
    {
        $targetPath = $this->tmpDir.'/single.txt';
        file_put_contents($targetPath, "just one line\n");

        $result = ($this->readFileTool)(['path' => $targetPath]);

        self::assertStringContainsString("     1\tjust one line", $result);
    }

    public function testReadWithRelativePath(): void
    {
        $relativePath = 'read_test_relative_'.bin2hex(random_bytes(4)).'.txt';
        $content = "relative\npath\ntest\n";
        $cwd = getcwd();

        try {
            file_put_contents($cwd.'/'.$relativePath, $content);

            $result = ($this->readFileTool)(['path' => $relativePath]);

            self::assertStringContainsString("     1\trelative", $result);
            self::assertStringContainsString("     2\tpath", $result);
            self::assertStringContainsString("     3\ttest", $result);
        } finally {
            if (is_file($cwd.'/'.$relativePath)) {
                unlink($cwd.'/'.$relativePath);
            }
        }
    }

    public function testReadLargeFileRespectsDefaultLimit(): void
    {
        $targetPath = $this->tmpDir.'/large.txt';
        // Create a file with 2500 lines
        $lines = [];
        for ($i = 1; $i <= 2500; ++$i) {
            $lines[] = "line {$i}";
        }
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        $result = ($this->readFileTool)(['path' => $targetPath]);

        // cat -n pads to 6 chars: "  2000\tline 2000"
        $expectedLine2000 = "  2000\tline 2000";
        self::assertStringContainsString($expectedLine2000, $result);

        // Should NOT show line 2001 (truncated by head)
        self::assertStringNotContainsString("  2001\tline 2001", $result);

        // Should include continuation hint
        self::assertStringContainsString('more lines', $result);
    }

    public function testContinuationHintAppearsForLimitedRead(): void
    {
        $targetPath = $this->tmpDir.'/hint_test.txt';
        $lines = [];
        for ($i = 1; $i <= 100; ++$i) {
            $lines[] = "data {$i}";
        }
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        $result = ($this->readFileTool)(['path' => $targetPath, 'offset' => 1, 'limit' => 10]);

        self::assertStringContainsString('more lines', $result);
        self::assertStringContainsString('offset=11', $result);
    }

    public function testReadFullyWithinBoundsNoContinuationHint(): void
    {
        $targetPath = $this->tmpDir.'/small_no_hint.txt';
        file_put_contents($targetPath, "a\nb\nc\n");

        $result = ($this->readFileTool)(['path' => $targetPath]);

        // Small file should not trigger continuation hint
        self::assertStringNotContainsString('more lines', $result);
    }

    /* ── __invoke() argument validation tests ── */

    public function testReadThrowsOnMissingPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->readFileTool)([]);
    }

    public function testReadThrowsOnEmptyPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->readFileTool)(['path' => '']);
    }

    public function testReadThrowsOnNonStringPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->readFileTool)(['path' => 42]);
    }

    public function testReadThrowsOnInvalidOffsetType(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"offset" argument must be an integer');

        ($this->readFileTool)(['path' => '/tmp/test.txt', 'offset' => 'not_an_int']);
    }

    public function testReadThrowsOnNegativeOffset(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('positive integer');

        ($this->readFileTool)(['path' => '/tmp/test.txt', 'offset' => 0]);
    }

    public function testReadThrowsOnInvalidLimitType(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"limit" argument must be an integer');

        ($this->readFileTool)(['path' => '/tmp/test.txt', 'limit' => '100']);
    }

    public function testReadThrowsOnZeroLimit(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('positive integer');

        ($this->readFileTool)(['path' => '/tmp/test.txt', 'limit' => 0]);
    }

    /* ── __invoke() target validation tests ── */

    public function testReadMissingFileThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not exist');

        ($this->readFileTool)(['path' => $this->tmpDir.'/nonexistent.txt']);
    }

    public function testReadDirectoryThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not a regular file');

        ($this->readFileTool)(['path' => $this->tmpDir]);
    }

    public function testReadDevicePathThrows(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('device paths are rejected');

        ($this->readFileTool)(['path' => '/dev/null']);
    }

    public function testReadUnreadableFileThrows(): void
    {
        $targetPath = $this->tmpDir.'/unreadable.txt';
        file_put_contents($targetPath, 'secret');
        chmod($targetPath, 0000);

        try {
            $this->expectException(ToolCallException::class);
            $this->expectExceptionMessage('not readable');

            ($this->readFileTool)(['path' => $targetPath]);
        } finally {
            chmod($targetPath, 0644);
        }
    }

    public function testReadBinaryFileThrows(): void
    {
        $targetPath = $this->tmpDir.'/binary.bin';
        file_put_contents($targetPath, "text\x00more\x00binary");

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('binary');

        ($this->readFileTool)(['path' => $targetPath]);
    }

    public function testReadNonUtf8FileThrows(): void
    {
        $targetPath = $this->tmpDir.'/non_utf8.bin';
        // Invalid UTF-8 sequence WITHOUT null bytes so it's not caught as binary
        // \xff\xfe is the UTF-16LE BOM, not valid standalone UTF-8 bytes
        file_put_contents($targetPath, "\xff\xfe\x01\x02");

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('non-UTF-8');

        ($this->readFileTool)(['path' => $targetPath]);
    }

    public function testReadImageByMimeThrows(): void
    {
        $targetPath = $this->tmpDir.'/fake.png';
        // Create a minimal but structurally valid PNG (signature + IHDR chunk)
        // so finfo identifies it as image/png
        $png = "\x89PNG\r\n\x1a\n"; // 8-byte PNG signature
        // IHDR chunk: 1x1 pixel, 8-bit RGB
        $ihdrData = pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0);
        $ihdrCrcData = 'IHDR'.$ihdrData;
        $png .= pack('N', 13); // chunk length
        $png .= 'IHDR';        // chunk type
        $png .= $ihdrData;     // chunk data (13 bytes)
        $png .= pack('N', crc32($ihdrCrcData)); // CRC32

        file_put_contents($targetPath, $png);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('image');

        ($this->readFileTool)(['path' => $targetPath]);
    }

    public function testReadImageByExtensionThrows(): void
    {
        $targetPath = $this->tmpDir.'/photo.jpg';
        // Plain text but .jpg extension
        file_put_contents($targetPath, "this is not really a jpg\n");

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('image');

        ($this->readFileTool)(['path' => $targetPath]);
    }

    public function testReadPdfByMimeThrows(): void
    {
        $targetPath = $this->tmpDir.'/doc.pdf';
        file_put_contents($targetPath, "%PDF-1.4 fake content\n");

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not a readable text format');

        ($this->readFileTool)(['path' => $targetPath]);
    }

    public function testReadProcFdPathThrows(): void
    {
        // This test creates a file under a path that matches /proc/*/fd/ pattern.
        // Since we can't actually create /proc/N/fd/*, we just verify the pattern match logic works.
        // We use the resolved path checker internally.
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('rejected for safety');

        ($this->readFileTool)(['path' => '/proc/1234/fd/0']);
    }

    /* ── OutputCap integration test ── */

    public function testReadWithOutputCap(): void
    {
        // Use a very low cap for this test
        $capConfig = new OutputCapConfig(
            storageDir: $this->tmpDir.'/output-cap-low',
            defaultCap: 10,
            docCap: 10,
        );
        $cap = new OutputCap($capConfig);
        $readTool = new ReadFileTool($this->toolRuntime, $cap);

        $targetPath = $this->tmpDir.'/cap_me.txt';
        file_put_contents($targetPath, "hello world this is a longer line that should exceed the cap\n");

        $result = ($readTool)(['path' => $targetPath]);

        // Output should be the capped notice
        self::assertStringContainsString('Output capped', $result);
    }

    public function testReadPassesThroughWhenUnderCap(): void
    {
        $targetPath = $this->tmpDir.'/under_cap.txt';
        file_put_contents($targetPath, "small content\n");

        $result = ($this->readFileTool)(['path' => $targetPath]);

        self::assertStringContainsString("     1\tsmall content", $result);
    }

    /* ── Cancellation tests ── */

    public function testReadCancelledBeforeExecutionThrows(): void
    {
        $targetPath = $this->tmpDir.'/cancelled.txt';
        file_put_contents($targetPath, "content\n");

        $token = $this->createToken(true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function () use ($targetPath): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');

                ($this->readFileTool)(['path' => $targetPath]);
            },
        );
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
            runId: 'read_test_run',
            turnNo: 1,
            toolCallId: 'read_call_1',
            toolName: 'read',
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
