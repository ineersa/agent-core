<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\WriteFileArgumentsDTO;

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
     * @return string Success message with byte count
     *
     * @throws \RuntimeException on filesystem errors or cancellation
     */
    public function __invoke(WriteFileArgumentsDTO $arguments): string
    {
        return $this->toolRuntime->run(static function () use ($arguments): string {
            $path = trim($arguments->path);
            if ('' === $path) {
                throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false);
            }
            if (null === $arguments->content) {
                throw new ToolCallException('The "content" argument is required and must be a string.', retryable: false);
            }
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
            // and file_put_contents below will produce the error.
            @mkdir(\dirname($resolvedPath), recursive: true);

            // Write content with exclusive lock
            $bytesWritten = @file_put_contents($resolvedPath, $content, \LOCK_EX);

            if (false === $bytesWritten) {
                throw new ToolCallException(\sprintf('Failed to write file "%s".', $resolvedPath), retryable: true, hint: 'Check file permissions and available disk space.');
            }

            return \sprintf('Successfully wrote %d bytes to %s', $bytesWritten, $resolvedPath);
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
            promptLine: 'write path content — create or overwrite a text file',
            promptGuidelines: [
                'The reported byte count reflects the written bytes after newline normalization.',
                'For targeted edits to existing file content, use the edit tool instead.',
            ],
        );
    }
}
