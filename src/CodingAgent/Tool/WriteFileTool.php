<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\WriteFileArgumentsDTO;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

use function Amp\delay;

/**
 * Write (create or replace) a file at the specified path.
 *
 * Implements HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and the Symfony AI native tool contract (typed DTO arguments).
 *
 * Features:
 * - Creates parent directories when they do not exist.
 * - Overwrites existing files.
 * - Checks cancellation before writing and during lock waits.
 * - Uses cooperative LOCK_EX acquisition for concurrent writes.
 */
final class WriteFileTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'write';

    public const string DESCRIPTION = 'Create a new file or overwrite an existing file with the given text content. Creates parent directories automatically if they do not exist. Non-empty text content is automatically newline-terminated for POSIX compatibility.';

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * Execute the write tool.
     *
     * @return string Success message with byte count
     *
     * @throws \RuntimeException on filesystem errors or cancellation
     */
    public function __invoke(
        #[MapToolArguments]
        WriteFileArgumentsDTO $arguments,
    ): string {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $path = $arguments->path;
            $content = $arguments->content;

            // Resolve the path to an absolute normalized form
            $resolvedPath = PathResolver::resolve($path);

            // Normalize non-empty content to POSIX text convention:
            // ensure non-empty files end with a single trailing newline so
            // subsequent edit tool operations work reliably.
            if ('' !== $content && !str_ends_with($content, "\n")) {
                $content .= "\n";
            }

            // Create parent directories if they do not exist.
            // If the parent path is an existing file, mkdir will fail silently
            // and opening the target below will produce the error.
            @mkdir(\dirname($resolvedPath), recursive: true);

            // Nontruncating open preserves the inode and existing bytes while
            // another Fiber or process owns the target-file lock.
            $stream = @fopen($resolvedPath, 'cb');
            if (false === $stream) {
                throw new ToolCallException(\sprintf('Failed to write file "%s".', $resolvedPath), retryable: true, hint: 'Check file permissions and available disk space.');
            }
            $acquired = false;
            try {
                do {
                    $this->toolRuntime->checkpoint();
                    $acquired = flock($stream, \LOCK_EX | \LOCK_NB, $wouldBlock);
                    if (!$acquired) {
                        if (0 === $wouldBlock) {
                            throw new ToolCallException(\sprintf('Failed to lock file "%s".', $resolvedPath), retryable: true);
                        }
                        delay(0.02);
                    }
                } while (!$acquired);
                $this->toolRuntime->checkpoint();

                // Do not yield or check cancellation during mutation.
                $bytesWritten = '' === $content ? 0 : @fwrite($stream, $content);
                if (false === $bytesWritten || $bytesWritten !== \strlen($content) || !@ftruncate($stream, $bytesWritten) || !fflush($stream)) {
                    throw new ToolCallException(\sprintf('Failed to write file "%s" (%d of %d bytes).', $resolvedPath, false === $bytesWritten ? 0 : $bytesWritten, \strlen($content)), retryable: true, hint: 'Check file permissions and available disk space.');
                }

                return \sprintf('Successfully wrote %d bytes to %s', $bytesWritten, $resolvedPath);
            } finally {
                if ($acquired) {
                    flock($stream, \LOCK_UN);
                }
                fclose($stream);
            }
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'write path content — create or overwrite a text file',
            promptGuidelines: [
                'The reported byte count reflects the written bytes after newline normalization.',
                'For targeted edits to existing file content, use the edit tool instead.',
            ],
        );
    }
}
