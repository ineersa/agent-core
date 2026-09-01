<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tests\Tool\Support\ToolValidationHarness;
use Ineersa\CodingAgent\Tool\Arguments\ReadFileArgumentsDTO;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\ReadFileTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Validator\Validation;

/**
 * @covers \Ineersa\CodingAgent\Tool\ReadFileTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 */
final class ReadFileToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private string $tmpDir;
    private ReadFileTool $readFileTool;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_read_test');

        // Output capping is handled centrally by tool-result processors.
        $this->readFileTool = new ReadFileTool($this->toolRuntime);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsRead(): void
    {
        $definition = $this->readFileTool->definition();

        $this->assertSame('read', $definition->name);
    }

    public function testDefinitionJsonSchemaHasPathOffsetLimit(): void
    {
        $definition = $this->readFileTool->definition();
        // Typed DTO tool: schema is generated natively from ReadFileArgumentsDTO.
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->readFileTool);
        $args = $schema['properties'];

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('path', $args);
        $this->assertArrayHasKey('offset', $args);
        $this->assertArrayHasKey('limit', $args);
        $this->assertContains('path', $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionExecutionModeIsParallel(): void
    {
        $definition = $this->readFileTool->definition();

        $this->assertSame('parallel', $definition->executionMode->value);
    }

    /* ── __invoke() success tests ── */

    public function testFullReadShowsPlainContent(): void
    {
        $targetPath = $this->tmpDir.'/numbered.txt';
        $lines = ['line one', 'line two', 'line three', 'line four', 'line five'];
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        $this->assertStringContainsString("line one\n", $result);
        $this->assertStringContainsString("line five\n", $result);

        // plain content
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
        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath, offset: 10, limit: 5));

        $this->assertStringContainsString("line 10\n", $result);
        $this->assertStringContainsString("line 14\n", $result);
        $this->assertStringNotContainsString("line 9\n", $result);

        // Ensure lines outside the range are NOT present
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
        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath, offset: 8));

        $this->assertStringContainsString("line 8\n", $result);
        $this->assertStringContainsString("line 10\n", $result);
    }

    public function testReadWithLimitOnly(): void
    {
        $targetPath = $this->tmpDir.'/limit_only.txt';
        file_put_contents($targetPath, "a\nb\nc\nd\ne\n");

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath, limit: 3));

        $this->assertStringContainsString("a\n", $result);
        $this->assertStringNotContainsString("d\n", $result);
    }

    public function testReadOffsetPastEofThrows(): void
    {
        $targetPath = $this->tmpDir.'/few_lines.txt';
        file_put_contents($targetPath, "line one\nline two\nline three\n");

        // Runs through the native toolbox + ValidateToolCallArgumentsListener,
        // where the ReadFileTarget DTO constraint rejects the offset.
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath, 'offset' => 10]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('offset 10 exceeds file length', $message);
        $this->assertStringContainsString('3 lines', $message);
        $this->assertStringNotContainsString('line one', $message);
    }

    public function testReadOffsetWithinRangeOnLargeFilePassesWithBoundedCounting(): void
    {
        // The ReadFileTarget validator must not load the whole file to prove
        // the offset is in range: counting stops as soon as `offset` lines are
        // seen, so a large file with a small in-range offset stays bounded.
        $targetPath = $this->tmpDir.'/large_in_range.txt';
        $lines = 200_000;
        $content = str_repeat("line\n", $lines);
        file_put_contents($targetPath, $content);

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = (string) $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath, 'offset' => 5]))->getResult();

        // No violation: the file reads normally from offset 5.
        $this->assertStringContainsString('line', $result);
    }

    public function testReadOffsetPastEofOnLargeFileCountsToEof(): void
    {
        // Past-EOF offsets must still report the exact line count, streaming
        // to EOF with a single handle instead of buffering the whole file.
        $targetPath = $this->tmpDir.'/large_past_eof.txt';
        $lines = 200_000;
        $content = str_repeat("line\n", $lines);
        file_put_contents($targetPath, $content);

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath, 'offset' => 500_000]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('offset 500000 exceeds file length', $message);
        $this->assertStringContainsString('200000 lines', $message);
    }

    public function testReadEmptyFile(): void
    {
        $targetPath = $this->tmpDir.'/empty.txt';
        file_put_contents($targetPath, '');

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        $this->assertSame('', $result);
    }

    public function testReadFileWithSingleLine(): void
    {
        $targetPath = $this->tmpDir.'/single.txt';
        file_put_contents($targetPath, "just one line\n");

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        $this->assertSame("just one line\n", $result);
    }

    public function testReadWithRelativePath(): void
    {
        $relativePath = 'read_test_relative_'.bin2hex(random_bytes(4)).'.txt';
        $content = "relative\npath\ntest\n";
        $cwd = getcwd();

        try {
            file_put_contents($cwd.'/'.$relativePath, $content);

            $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $relativePath));

            $this->assertStringContainsString("relative\n", $result);
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

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        // default cap at 2000 lines
        $expectedLine2000 = 'line 2000';
        $this->assertStringContainsString($expectedLine2000, $result);

        // Default limit stops before line 2001.
        $this->assertStringNotContainsString("line 2001\n", $result);

        // Should include continuation hint
        $this->assertStringContainsString('more lines', $result);
    }

    public function testContinuationHintAppearsForLimitedRead(): void
    {
        $targetPath = $this->tmpDir.'/hint_test.txt';
        $lines = [];
        for ($i = 1; $i <= 100; ++$i) {
            $lines[] = "data {$i}";
        }
        file_put_contents($targetPath, implode("\n", $lines)."\n");

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath, offset: 1, limit: 10));

        $this->assertStringContainsString('more lines', $result);
        $this->assertStringContainsString('offset=11', $result);
    }

    public function testReadFullyWithinBoundsNoContinuationHint(): void
    {
        $targetPath = $this->tmpDir.'/small_no_hint.txt';
        file_put_contents($targetPath, "a\nb\nc\n");

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        // Small file should not trigger continuation hint
        $this->assertStringNotContainsString('more lines', $result);
    }

    /* ── Static argument validation lives in the DTO (enforced by the native
       ValidateToolCallArgumentsListener before the handler runs) ── */

    public function testDtoRejectsBlankPath(): void
    {
        $violations = $this->validateDto(new ReadFileArgumentsDTO());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"path" argument is required', $violations[0]->getMessage());
    }

    public function testDtoRejectsNonPositiveOffsetAndLimit(): void
    {
        $targetPath = $this->tmpDir.'/offset_limit.txt';
        file_put_contents($targetPath, "a\n");

        $violations = $this->validateDto(new ReadFileArgumentsDTO(path: $targetPath, offset: 0, limit: 0));

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('positive integer', $violations[0]->getMessage());
        $this->assertStringContainsString('positive integer', $violations[1]->getMessage());
    }

    /* ── Validation-level target rejection tests ──
       Invalid targets are rejected before execution by the ReadFileTarget
       DTO constraint via the native toolbox + ValidateToolCallArgumentsListener;
       the handler never runs, so its messages/content never appear. */

    public function testReadMissingFileThrows(): void
    {
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $this->tmpDir.'/nonexistent.txt']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist', $message);
        $this->assertStringContainsString('Check the file path and try again.', $message);
        $this->assertStringNotContainsString('Failed to read file', $message);
    }

    public function testReadDirectoryThrows(): void
    {
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $this->tmpDir]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('not a regular file', $message);
        $this->assertStringContainsString('Use the read tool only for regular files.', $message);
    }

    public function testReadDevicePathThrows(): void
    {
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => '/dev/null']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('device paths are rejected', $message);
        $this->assertStringContainsString('Specify a regular file path.', $message);
    }

    public function testReadUnreadableFileThrows(): void
    {
        $targetPath = $this->tmpDir.'/unreadable.txt';
        file_put_contents($targetPath, 'secret');
        chmod($targetPath, 0000);

        try {
            $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
            $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

            $message = (string) $result->getResult();
            $this->assertStringContainsString('not readable', $message);
            $this->assertStringContainsString('Check file permissions and try again.', $message);
        } finally {
            chmod($targetPath, 0644);
        }
    }

    public function testReadBinaryFileThrows(): void
    {
        $targetPath = $this->tmpDir.'/binary.bin';
        file_put_contents($targetPath, "text\x00more\x00binary");

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $message = (string) $result->getResult();
        // finfo classifies null-byte content as application/octet-stream, so
        // the MIME rejection fires before the null-byte branch — identical to
        // the pre-refactor ordering (the old 'binary' assertion actually
        // matched the .bin path substring, not the rejection reason).
        $this->assertStringContainsString('application/octet-stream', $message);
        $this->assertStringContainsString('not a readable text format', $message);
        $this->assertStringNotContainsString('more', $message); // handler never ran
    }

    public function testReadNonUtf8FileThrows(): void
    {
        $targetPath = $this->tmpDir.'/non_utf8.bin';
        // Invalid UTF-8 sequence WITHOUT null bytes so it's not caught as binary
        // \xff\xfe is the UTF-16LE BOM, not valid standalone UTF-8 bytes
        file_put_contents($targetPath, "\xff\xfe\x01\x02");

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('non-UTF-8', $message);
        $this->assertStringContainsString('Convert the file to UTF-8 encoding first', $message);
    }

    public function testReadValidUtf8Near8192Boundary(): void
    {
        // Create a file where a 4-byte UTF-8 character starts at byte 8189
        // so the 8192-byte sample buffer ends during the multi-byte sequence.
        // This would falsely trigger a non-UTF-8 error with the old
        // fixed-size fread + mb_check_encoding approach.
        $targetPath = $this->tmpDir.'/utf8_boundary.txt';
        $prefix = str_repeat('a', 8189);
        // U+1F600 GRINNING FACE = \xF0\x9F\x98\x80 (4 bytes)
        $emoji = "\xF0\x9F\x98\x80";
        $trailing = "\nsome content after the boundary\n";
        file_put_contents($targetPath, $prefix.$emoji.$trailing);

        // Runs through the native toolbox so the ReadFileTarget validator
        // exercises its sample/lookahead acceptance path.
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = (string) $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]))->getResult();

        $this->assertStringContainsString('some content after the boundary', $result);
        $this->assertStringContainsString('a', $result);
    }

    public function testReadValidUtf8WithBoxDrawing(): void
    {
        $targetPath = $this->tmpDir.'/box_drawing.txt';
        // Box drawing characters (U+2500-U+257F) are 3-byte UTF-8.
        // These appear in docs/tui-architecture.md and are common
        // in text files that read should handle correctly.
        $content = "┌───┐\n│ x │\n└───┘\n";
        file_put_contents($targetPath, $content);

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        $this->assertStringContainsString('┌───┐', $result);
        $this->assertStringContainsString('│ x │', $result);
        $this->assertStringContainsString('└───┘', $result);
    }

    public function testReadInvalidUtf8At8192BoundaryThrows(): void
    {
        // Create a file where 8191 ASCII bytes are followed by an invalid
        // UTF-8 byte (\xFF), then a suffix. The old trimToCompleteUtf8Prefix
        // would silently remove the trailing \xFF and accept the file.
        // The fix must reject: the 8192-byte sample with trailing invalid
        // bytes is genuinely non-UTF-8, not a boundary truncation.
        $targetPath = $this->tmpDir.'/invalid_utf8_at_boundary.txt';
        $prefix = str_repeat('a', 8191);
        $invalidByte = "\xFF";
        $suffix = "\nmore content\n";
        file_put_contents($targetPath, $prefix.$invalidByte.$suffix);

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $this->assertStringContainsString('non-UTF-8', (string) $result->getResult());
    }

    public function testReadInvalidUtf8At8190BoundaryWithContinuationByteThrows(): void
    {
        // Create a file where 8190 ASCII bytes are followed by an invalid
        // stray continuation byte (\xBA) and then an invalid byte (\xFF).
        // The trailing \xFF is NOT a continuation byte, so the safe
        // continuation-byte trim must stop before it and reject.
        $targetPath = $this->tmpDir.'/invalid_utf8_at_8190_boundary.txt';
        $prefix = str_repeat('a', 8190);
        $invalidByte = "\xBA\xFF"; // stray continuation + invalid start byte
        $suffix = "\nmore content\n";
        file_put_contents($targetPath, $prefix.$invalidByte.$suffix);

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $this->assertStringContainsString('non-UTF-8', (string) $result->getResult());
    }

    public function testReadValidUtf8With8192AsciiFollowedByEmoji(): void
    {
        // Create a file where 8192 ASCII bytes (exactly one inspection sample)
        // are followed by a 4-byte emoji.  The base sample (8192 ASCII bytes)
        // is valid UTF-8, so the file passes even though the lookahead captures
        // only the first 3 bytes of the 4-byte emoji.
        $targetPath = $this->tmpDir.'/utf8_8192_plus_emoji.txt';
        $prefix = str_repeat('a', 8192);
        // U+1F600 GRINNING FACE = \xF0\x9F\x98\x80 (4 bytes)
        $emoji = "\xF0\x9F\x98\x80";
        file_put_contents($targetPath, $prefix.$emoji);

        // Runs through the native toolbox so the ReadFileTarget validator
        // exercises its sample/lookahead acceptance path.
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = (string) $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]))->getResult();

        $this->assertStringContainsString('a', $result);
        $this->assertStringContainsString($emoji, $result);
    }

    public function testReadInvalidUtf8EndingWithStrayContinuationByteThrows(): void
    {
        // Create a file <= 8192 bytes that ends with a stray continuation
        // byte.  This must be rejected — the sample is the entire file, so
        // no "lookahead" is available and trimming at EOF is not allowed.
        $targetPath = $this->tmpDir.'/invalid_stray_continuation.txt';
        // 8191 ASCII bytes + a stray continuation byte (0x80)
        $prefix = str_repeat('a', 8191);
        $strayContinuation = "\x80";
        file_put_contents($targetPath, $prefix.$strayContinuation);

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $this->assertStringContainsString('non-UTF-8', (string) $result->getResult());
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

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('image', $message);
        $this->assertStringContainsString('Use the view_image tool instead.', $message);
    }

    public function testReadImageByExtensionThrows(): void
    {
        $targetPath = $this->tmpDir.'/photo.jpg';
        // Plain text but .jpg extension
        file_put_contents($targetPath, "this is not really a jpg\n");

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('image', $message);
        $this->assertStringContainsString('looks like an image file', $message);
    }

    public function testReadPdfByMimeThrows(): void
    {
        $targetPath = $this->tmpDir.'/doc.pdf';
        file_put_contents($targetPath, "%PDF-1.4 fake content\n");

        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => $targetPath]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('not a readable text format', $message);
        $this->assertStringContainsString('not supported by the read tool', $message);
    }

    public function testReadProcFdPathThrows(): void
    {
        // Path matching the /proc/*/fd/ safety pattern is rejected by the
        // ReadFileTarget validator before any filesystem access.
        $toolbox = ToolValidationHarness::toolbox($this->readFileTool);
        $result = $toolbox->execute(new ToolCall('call-read', 'read', ['path' => '/proc/1234/fd/0']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('rejected for safety', $message);
        $this->assertStringContainsString('Specify a regular file path.', $message);
    }

    /* ── OutputCap integration test ── */

    public function testReadWithOutputCap(): void
    {
        // Output capping is now handled centrally by OutputCapToolResultProcessor.
        // This test verifies the read tool returns raw output without embedding
        // any cap notice in the result string.
        $capConfig = new OutputCapConfig(
            storageDir: $this->tmpDir.'/output-cap-low',
            defaultCap: 10,
            docCap: 10,
        );
        $cap = $this->outputCap($capConfig);
        $readTool = new ReadFileTool($this->toolRuntime);

        $targetPath = $this->tmpDir.'/cap_me.txt';
        file_put_contents($targetPath, "hello world this is a longer line that should exceed the cap\n");

        $result = ($readTool)(new ReadFileArgumentsDTO(path: $targetPath));

        // Tool returns raw output; capping is centralized.
        $this->assertStringContainsString('this is a longer line', $result);
        $this->assertStringNotContainsString('Output capped', $result);
    }

    public function testReadPassesThroughWhenUnderCap(): void
    {
        $targetPath = $this->tmpDir.'/under_cap.txt';
        file_put_contents($targetPath, "small content\n");

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        $this->assertStringContainsString('small content', $result);
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

                ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));
            },
        );
    }

    public function testReadWithUtf8BomIsAccepted(): void
    {
        // UTF-8 BOM (\xEF\xBB\xBF) is valid UTF-8 for U+FEFF ZERO WIDTH NO-BREAK SPACE.
        // The read tool must accept it as a valid UTF-8 text file.
        $targetPath = $this->tmpDir.'/utf8_bom.txt';
        $content = "\xEF\xBB\xBFHello, UTF-8 BOM world!\n";
        file_put_contents($targetPath, $content);

        $result = ($this->readFileTool)(new ReadFileArgumentsDTO(path: $targetPath));

        $this->assertStringContainsString('Hello, UTF-8 BOM world!', $result);
    }

    private function validateDto(object $dto): array
    {
        return iterator_to_array(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($dto));
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

    private function outputCap(OutputCapConfig $config): OutputCap
    {
        return new OutputCap($config, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger());
    }
}
