<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;

/**
 * Write (create or replace) a file at the specified path.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * Features:
 * - Creates parent directories when they do not exist.
 * - Overwrites existing files.
 * - Checks cancellation before writing and before returning.
 * - Uses LOCK_EX for safe concurrent writes.
 */
final class WriteFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * Execute the write tool.
     *
     * @param array<string, mixed> $arguments Must contain 'path' (string) and 'content' (string)
     *
     * @return string Success message with byte count
     *
     * @throws \RuntimeException on filesystem errors or cancellation
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(static function () use ($arguments): string {
            // Validate required arguments
            $path = $arguments['path'] ?? null;
            $content = $arguments['content'] ?? null;

            if (!\is_string($path) || '' === $path) {
                throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path.');
            }

            if (!\is_string($content)) {
                throw new ToolCallException('The "content" argument is required and must be a string.', retryable: false, hint: 'Provide the text content to write.');
            }

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
            // and file_put_contents below will produce the error.
            @mkdir(\dirname($resolvedPath), recursive: true);

            // Write content with exclusive lock
            $bytesWritten = @file_put_contents($resolvedPath, $content, \LOCK_EX);

            if (false === $bytesWritten) {
                throw new ToolCallException(\sprintf('Failed to write file "%s".', $resolvedPath), retryable: true, hint: 'Check file permissions and available disk space.');
            }

            return \sprintf('Successfully wrote %d bytes to %s', $bytesWritten, $path);
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'write',
            description: 'Create a new file or overwrite an existing file with the given text content. Creates parent directories automatically if they do not exist. Non-empty text content is automatically newline-terminated for POSIX compatibility.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to write (absolute, or relative to the working directory)',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Text content to write to the file',
                    ],
                ],
                'required' => ['path', 'content'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'write path content — create or overwrite a file; creates parent directories automatically; non-empty text is newline-terminated',
            promptGuidelines: [
                'Non-empty content is automatically newline-terminated for POSIX text compatibility and edit tool reliability.',
                'Parent directories are created automatically if they do not exist.',
                'Overwrites the file entirely if it already exists.',
                'Use when creating new files or replacing file content entirely.',
                'The reported byte count reflects the written bytes after newline normalization.',
                'For targeted edits to existing file content, use the edit tool instead.',
            ],
        );
    }
}
