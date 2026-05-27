<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

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
    private const int DEFAULT_DIR_PERMISSIONS = 0750;

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
     * @throws \InvalidArgumentException on missing or invalid arguments
     * @throws \RuntimeException         on filesystem errors or cancellation
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(static function () use ($arguments): string {
            // Validate required arguments
            $path = $arguments['path'] ?? null;
            $content = $arguments['content'] ?? null;

            if (!\is_string($path) || '' === $path) {
                throw new \InvalidArgumentException('The "path" argument is required and must be a non-empty string.');
            }

            if (!\is_string($content)) {
                throw new \InvalidArgumentException('The "content" argument is required and must be a string.');
            }

            // Resolve the path to an absolute normalized form
            $resolvedPath = PathResolver::resolve($path);

            // Create parent directory if it does not exist.
            // Must check for existing file BEFORE the !is_dir check because
            // is_dir() returns false for a file path, which would incorrectly
            // trigger a mkdir() attempt on an existing file.
            $parentDir = \dirname($resolvedPath);
            if (is_file($parentDir)) {
                throw new \RuntimeException(\sprintf('Cannot create file "%s": parent path "%s" is an existing file, not a directory.', $resolvedPath, $parentDir));
            }
            if (!is_dir($parentDir)) {
                $mkdirResult = @mkdir($parentDir, self::DEFAULT_DIR_PERMISSIONS, recursive: true);
                if (!$mkdirResult && !is_dir($parentDir)) {
                    throw new \RuntimeException(\sprintf('Failed to create parent directory "%s" for path "%s".', $parentDir, $resolvedPath));
                }
            }

            // Write content with exclusive lock
            $bytesWritten = @file_put_contents($resolvedPath, $content, \LOCK_EX);

            if (false === $bytesWritten) {
                throw new \RuntimeException(\sprintf('Failed to write file "%s".', $resolvedPath));
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
            description: 'Create a new file or overwrite an existing file with the given text content. Creates parent directories automatically if they do not exist.',
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
            promptLine: 'write path content — create or overwrite a file with exact text content; creates parent directories automatically',
            promptGuidelines: [
                'Write the exact content provided — do not trim, format, or modify it.',
                'Parent directories are created automatically if they do not exist.',
                'Overwrites the file entirely if it already exists.',
                'Use when creating new files or replacing file content entirely.',
                'For targeted edits to existing file content, use the edit tool instead.',
            ],
        );
    }
}
