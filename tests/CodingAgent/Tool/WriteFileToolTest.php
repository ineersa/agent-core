<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\Arguments\WriteFileArgumentsDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Ineersa\CodingAgent\Tool\WriteFileTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

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

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_write_test');

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

    public function testDefinitionJsonSchemaHasPathAndContent(): void
    {
        $definition = $this->writeFileTool->definition();
        // Typed DTO tool: schema is generated natively from WriteFileArgumentsDTO.
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->writeFileTool);
        $args = $schema['properties'];

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('path', $args);
        $this->assertArrayHasKey('content', $args);
        $this->assertContains('path', $schema['required']);
        $this->assertContains('content', $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    /* ── __invoke() success tests ── */

    public function testWriteOverwritesWriteOnlyFile(): void
    {
        $path = $this->tmpDir.'/write-only.txt';
        file_put_contents($path, 'old bytes');
        chmod($path, 0200);
        try {
            if (is_readable($path) || !is_writable($path)) {
                $this->markTestSkipped('Runtime does not enforce write-only file permissions.');
            }
            $inode = fileinode($path);
            ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $path, content: 'new'));
            $this->assertSame($inode, fileinode($path));
            chmod($path, 0600);
            $this->assertSame("new\n", file_get_contents($path));
        } finally {
            chmod($path, 0600);
        }
    }

    public function testWriteCreatesNewFile(): void
    {
        $targetPath = $this->tmpDir.'/new_file.txt';
        $content = 'Hello, World!';

        $result = ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $content));

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

        $result = ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $content));

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
        $result = ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $newContent));

        $this->assertStringContainsString('Successfully', $result);
        // Non-empty content without trailing newline is normalized
        $this->assertSame("New content replacing the old one.\n", file_get_contents($targetPath));
    }

    public function testTargetLockWaitYieldsAndCancellationDoesNotTruncate(): void
    {
        foreach ([false, true] as $cancel) {
            $path = $this->tmpDir.'/contended-'.(int) $cancel.'.txt';
            file_put_contents($path, "original longer content\n");
            $owner = fopen($path, 'r+b');
            $this->assertTrue(flock($owner, \LOCK_EX | \LOCK_NB));
            $cancelled = false;
            $token = $this->createStub(CancellationTokenInterface::class);
            $token->method('isCancellationRequested')->willReturnCallback(static function () use (&$cancelled): bool { return $cancelled; });
            $entered = new DeferredFuture();
            $waiting = \Amp\async(fn () => $this->contextAccessor->with(
                $this->contextWithToken($token),
                function () use ($path, $entered): string {
                    $entered->complete();

                    return ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $path, content: 'new'));
                },
            ));
            try {
                $entered->getFuture()->await(new TimeoutCancellation(2));
                \Amp\async(static function () use ($waiting, $path): void {
                    self::assertFalse($waiting->isComplete());
                    self::assertSame("original longer content\n", file_get_contents($path));
                })->await(new TimeoutCancellation(2));
                if ($cancel) {
                    $cancelled = true;
                    try {
                        $waiting->await(new TimeoutCancellation(2));
                        $this->fail('Expected cancellation while the target lock is held.');
                    } catch (\RuntimeException $e) {
                        $this->assertStringContainsString('cancelled', $e->getMessage());
                    }
                    $this->assertSame("original longer content\n", file_get_contents($path));
                }
                flock($owner, \LOCK_UN);
                if (!$cancel) {
                    $this->assertStringContainsString('Successfully wrote 4 bytes', $waiting->await(new TimeoutCancellation(2)));
                }
                ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $path, content: 'final'));
                $this->assertSame("final\n", file_get_contents($path));
            } finally {
                $cancelled = true;
                flock($owner, \LOCK_UN);
                fclose($owner);
            }
        }
    }

    public function testWritePreservesSymlinkAndHardlink(): void
    {
        $path = $this->tmpDir.'/target.txt';
        $symlink = $this->tmpDir.'/symbolic.txt';
        $hardlink = $this->tmpDir.'/hard.txt';
        file_put_contents($path, "old longer content\n");
        symlink($path, $symlink);
        link($path, $hardlink);
        ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $symlink, content: 'new'));
        $this->assertTrue(is_link($symlink));
        $this->assertSame("new\n", file_get_contents($hardlink));
        ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $hardlink, content: ''));
        $this->assertSame('', file_get_contents($path));
    }

    public function testCancellationAfterTargetAcquisitionDoesNotMutate(): void
    {
        $path = $this->tmpDir.'/post-acquire.txt';
        file_put_contents($path, "original\n");
        $checks = 0;
        $token = $this->createStub(CancellationTokenInterface::class);
        // Pre-start, pre-acquire, then the checkpoint with the target locked.
        $token->method('isCancellationRequested')->willReturnCallback(static function () use (&$checks): bool { return ++$checks >= 3; });
        try {
            $this->contextAccessor->with($this->contextWithToken($token), fn () => ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $path, content: 'new')));
            $this->fail('Expected cancellation before mutation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
        $this->assertSame("original\n", file_get_contents($path));
        $stream = fopen($path, 'r+b');
        $this->assertTrue(flock($stream, \LOCK_EX | \LOCK_NB));
        flock($stream, \LOCK_UN);
        fclose($stream);
    }

    public function testWriteReturnsByteCount(): void
    {
        $targetPath = $this->tmpDir.'/bytecount.txt';
        $content = str_repeat('A', 1000);

        $result = ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $content));

        // Non-empty content without trailing newline: one extra byte for \n
        $this->assertStringContainsString('1001 bytes', $result);
    }

    public function testWriteEmptyContent(): void
    {
        $targetPath = $this->tmpDir.'/empty.txt';

        $result = ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: ''));

        $this->assertStringContainsString('0 bytes', $result);
        $this->assertFileExists($targetPath);
        $this->assertSame('', file_get_contents($targetPath));
    }

    public function testWriteWithRelativePathResolvesAgainstCwd(): void
    {
        $relativePath = 'write_test_relative_'.bin2hex(random_bytes(4)).'.txt';
        $content = 'Relative path test.';

        try {
            $result = ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $relativePath, content: $content));

            $cwd = getcwd();
            $this->assertFileExists($cwd.'/'.$relativePath);
            $this->assertStringContainsString($cwd.'/'.$relativePath, $result);
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

    /* ── Static argument validation lives in the DTO (enforced by the native
       ValidateToolCallArgumentsListener before the handler runs) ── */

    public function testDtoRejectsBlankPath(): void
    {
        $violations = $this->validateDto(new WriteFileArgumentsDTO(path: '', content: 'some content'));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"path" argument is required', $violations[0]->getMessage());
    }

    public function testDtoRejectsNullContent(): void
    {
        $violations = $this->validateDto(new WriteFileArgumentsDTO(path: $this->tmpDir.'/test.txt', content: null));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"content" argument is required', $violations[0]->getMessage());
    }

    public function testWriteThrowsWhenParentExistsAsFile(): void
    {
        $existingFile = $this->tmpDir.'/existing_file.txt';
        file_put_contents($existingFile, 'I am a file, not a directory.');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Failed to write file');

        ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $existingFile.'/child.txt', content: 'cannot create'));
    }

    /* ── Trailing newline normalization tests ── */

    public function testWriteAppendsNewlineToNonEmptyContent(): void
    {
        $targetPath = $this->tmpDir.'/newline_added.txt';
        $content = 'No trailing newline';

        ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $content));

        $this->assertSame("No trailing newline\n", file_get_contents($targetPath));
    }

    public function testWriteDoesNotDoubleNewlineWhenAlreadyPresent(): void
    {
        $targetPath = $this->tmpDir.'/no_double_newline.txt';
        $content = "Has trailing newline\n";

        ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $content));

        $this->assertSame("Has trailing newline\n", file_get_contents($targetPath));
    }

    public function testWriteDoesNotModifyCrlfEnding(): void
    {
        $targetPath = $this->tmpDir.'/crlf_content.txt';
        $content = "line1\r\n";

        ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $targetPath, content: $content));

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

                ($this->writeFileTool)(new WriteFileArgumentsDTO(path: $this->tmpDir.'/cancelled.txt', content: 'Should not be written.'));
            },
        );

        // The file should NOT exist because cancellation happened before execution
        $this->assertFileDoesNotExist($this->tmpDir.'/cancelled.txt');
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
