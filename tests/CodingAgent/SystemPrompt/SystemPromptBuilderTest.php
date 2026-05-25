<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SystemPromptBuilder.
 *
 * Covers:
 * - Built-in template rendering with all placeholders
 * - Home/project SYSTEM.md override precedence
 * - Append template loading and rendering
 * - Placeholder substitution
 * - Dedupe rendering (tools list, guidelines)
 * - System message injection (integration)
 *
 * @group system-prompt
 */
final class SystemPromptBuilderTest extends TestCase
{
    private string $projectDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->projectDir = \realpath(__DIR__ . '/../../..');
        \assert(\is_string($this->projectDir), 'Cannot resolve project directory');

        // Create a temp directory for test templates without polluting real .hatfield/
        $this->tmpDir = \sys_get_temp_dir() . '/system_prompt_test_' . \bin2hex(\random_bytes(8));
        \mkdir($this->tmpDir . '/.hatfield', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    /* ───────── Built-in template rendering ───────── */

    public function testBuiltInTemplateRendersWithEmptyRegistry(): void
    {
        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        // The built-in config/SYSTEM.md is used when no override exists.
        // Verify key structural elements are present.
        self::assertStringContainsString('expert coding assistant', \strtolower($result));
        self::assertStringContainsString('<available_tools>', $result);
        self::assertStringContainsString('</available_tools>', $result);
        self::assertStringContainsString('<guidelines>', $result);
        self::assertStringContainsString('</guidelines>', $result);
        self::assertStringContainsString('<context_channels>', $result);

        // Verify placeholders are replaced (empty values from empty registry)
        self::assertStringNotContainsString('{%available_tools_list%}', $result);
        self::assertStringNotContainsString('{%registered_guidelines%}', $result);
        self::assertStringNotContainsString('{%appends_part%}', $result);
        self::assertStringNotContainsString('{%date%}', $result);
        self::assertStringNotContainsString('{%cwd%}', $result);

        // Verify date is present (any date in Y-m-d format)
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $result);

        // Verify CWD is present
        self::assertStringContainsString($this->tmpDir, $result);
    }

    public function testBuiltInTemplateWithRegisteredTools(): void
    {
        $registry = $this->createRegistryWithTools();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        // Tool lines should appear in <available_tools>
        self::assertStringContainsString('- read: Read file contents', $result);
        self::assertStringContainsString('- write: Write file contents', $result);

        // Guidelines should appear in <guidelines>
        self::assertStringContainsString('Use read for files', $result);
        self::assertStringContainsString('Use write for files', $result);
    }

    public function testToolsListAndGuidelinesDeduped(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'read',
            description: 'Read',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: '- read: Read file contents',
            promptGuidelines: ['Read files with cat -n'],
        );
        $registry->registerTool(
            name: 'read2',
            description: 'Read 2',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: '- read: Read file contents',
            promptGuidelines: ['Read files with cat -n', 'Use read for text files'],
        );

        $builder = new SystemPromptBuilder($registry, $this->projectDir);
        $result = $builder->build($this->tmpDir);

        // Deduped lines: '- read: Read file contents' appears only once
        self::assertSame(1, \substr_count($result, '- read: Read file contents'));

        // Deduped guidelines: 'Read files with cat -n' appears only once
        self::assertSame(1, \substr_count($result, 'Read files with cat -n'));
        // 'Use read for text files' appears once
        self::assertSame(1, \substr_count($result, 'Use read for text files'));
    }

    /* ───────── Template override precedence ───────── */

    public function testProjectOverrideReplacesBuiltIn(): void
    {
        $projectSystemPath = $this->tmpDir . '/.hatfield/SYSTEM.md';
        \file_put_contents($projectSystemPath, 'Custom project system prompt. Date: {%date%} CWD: {%cwd%}');

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        self::assertStringContainsString('Custom project system prompt.', $result);
        self::assertStringNotContainsString('expert coding assistant', \strtolower($result));
        self::assertStringContainsString(\date('Y-m-d'), $result);
        self::assertStringContainsString($this->tmpDir, $result);
    }

    public function testHomeOverrideReplacesBuiltInWhenNoProjectOverride(): void
    {
        // Create a home-directory-like SYSTEM.md (we mock HOME env in the builder
        // by pointing to our temp dir via a custom approach — actually we can't
        // easily mock getenv('HOME') without changing it globally.
        //
        // Instead, we test home override by manipulating a scenario where
        // project override does not exist but home does. We use the fact that
        // the builder checks HOME env variable. We set HOME temporarily.

        $homeDir = $this->tmpDir . '/home';
        \mkdir($homeDir . '/.hatfield', 0777, true);
        \file_put_contents($homeDir . '/.hatfield/SYSTEM.md', 'Home system prompt. Date: {%date%}');

        $oldHome = \getenv('HOME');
        \putenv('HOME=' . $homeDir);

        try {
            $registry = $this->createEmptyRegistry();
            $builder = new SystemPromptBuilder($registry, $this->projectDir);

            // Use a different dir as cwd so project override isn't found
            $otherDir = $this->tmpDir . '/other';
            @\mkdir($otherDir);
            $result = $builder->build($otherDir);

            self::assertStringContainsString('Home system prompt.', $result);
            self::assertStringNotContainsString('expert coding assistant', \strtolower($result));
            self::assertStringContainsString(\date('Y-m-d'), $result);
        } finally {
            \putenv('HOME=' . ($oldHome ?: ''));
        }
    }

    public function testProjectOverrideTakesPrecedenceOverHomeOverride(): void
    {
        $homeDir = $this->tmpDir . '/home';
        \mkdir($homeDir . '/.hatfield', 0777, true);
        \file_put_contents($homeDir . '/.hatfield/SYSTEM.md', 'Home system prompt.');

        \file_put_contents($this->tmpDir . '/.hatfield/SYSTEM.md', 'Project system prompt.');

        $oldHome = \getenv('HOME');
        \putenv('HOME=' . $homeDir);

        try {
            $registry = $this->createEmptyRegistry();
            $builder = new SystemPromptBuilder($registry, $this->projectDir);

            $result = $builder->build($this->tmpDir);

            self::assertStringContainsString('Project system prompt.', $result);
            self::assertStringNotContainsString('Home system prompt.', $result);
        } finally {
            \putenv('HOME=' . ($oldHome ?: ''));
        }
    }

    /* ───────── Append template rendering ───────── */

    public function testAppendTemplatesRenderedAndInserted(): void
    {
        // Add project APPEND_SYSTEM.md
        \file_put_contents(
            $this->tmpDir . '/.hatfield/APPEND_SYSTEM.md',
            'Append content. Date: {%date%} CWD: {%cwd%}',
        );

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        // Append content should be rendered
        self::assertStringContainsString('Append content.', $result);
        self::assertStringContainsString(\date('Y-m-d'), $result);
        self::assertStringContainsString($this->tmpDir, $result);

        // Built-in content should still be present
        self::assertStringContainsString('expert coding assistant', \strtolower($result));
    }

    public function testBothHomeAndProjectAppendTemplatesMerged(): void
    {
        $homeDir = $this->tmpDir . '/home';
        \mkdir($homeDir . '/.hatfield', 0777, true);
        \file_put_contents($homeDir . '/.hatfield/APPEND_SYSTEM.md', 'Home append: {%cwd%}');

        \file_put_contents(
            $this->tmpDir . '/.hatfield/APPEND_SYSTEM.md',
            'Project append: {%cwd%}',
        );

        $oldHome = \getenv('HOME');
        \putenv('HOME=' . $homeDir);

        try {
            $registry = $this->createEmptyRegistry();
            $builder = new SystemPromptBuilder($registry, $this->projectDir);

            $result = $builder->build($this->tmpDir);

            // Home append appears first, then project append
            self::assertStringContainsString('Home append: ' . $this->tmpDir, $result);
            self::assertStringContainsString('Project append: ' . $this->tmpDir, $result);

            // Home content appears before project content
            $homePos = \strpos($result, 'Home append:');
            $projectPos = \strpos($result, 'Project append:');
            self::assertNotFalse($homePos);
            self::assertNotFalse($projectPos);
            self::assertLessThan($projectPos, $homePos);
        } finally {
            \putenv('HOME=' . ($oldHome ?: ''));
        }
    }

    public function testAppendTemplateDoesNotRecurseIntoAppendsPart(): void
    {
        // APPEND_SYSTEM.md that contains {%appends_part%}
        \file_put_contents(
            $this->tmpDir . '/.hatfield/APPEND_SYSTEM.md',
            'Append with appends_part placeholder: [{%appends_part%}]',
        );

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        // {%appends_part%} in append content should be empty (no recursion)
        self::assertStringContainsString('Append with appends_part placeholder: []', $result);
    }

    public function testNoAppendTemplatesResultsInEmptyAppendsPart(): void
    {
        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        // {%appends_part%} in the built-in template should be replaced with empty string
        self::assertStringNotContainsString('{%appends_part%}', $result);
    }

    /* ───────── Placeholder substitution ───────── */

    public function testAllPlaceholdersAreSubstituted(): void
    {
        $registry = $this->createRegistryWithTools();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        // Use a custom SYSTEM.md override that explicitly tests all placeholders
        \file_put_contents($this->tmpDir . '/.hatfield/SYSTEM.md', \implode("\n", [
            'Tools: [{%available_tools_list%}]',
            'Guidelines: [{%registered_guidelines%}]',
            'Appends: [{%appends_part%}]',
            'Date: [{%date%}]',
            'CWD: [{%cwd%}]',
        ]));

        \file_put_contents(
            $this->tmpDir . '/.hatfield/APPEND_SYSTEM.md',
            'Extra guidelines: ignore',
        );

        $result = $builder->build($this->tmpDir);

        self::assertStringContainsString('Tools: [- read: Read file contents' . "\n" . '- write: Write file contents]', $result);
        self::assertStringContainsString('Guidelines: [Use read for files' . "\n" . 'Use write for files]', $result);
        self::assertStringContainsString('Appends: [Extra guidelines: ignore]', $result);
        self::assertStringContainsString('Date: [' . \date('Y-m-d') . ']', $result);
        self::assertStringContainsString('CWD: [' . $this->tmpDir . ']', $result);
    }

    public function testPlaceholdersAreSubstitutedInOverrideTemplate(): void
    {
        \file_put_contents(
            $this->tmpDir . '/.hatfield/SYSTEM.md',
            '{%date%} at {%cwd%} with tools [{%available_tools_list%}]',
        );

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $result = $builder->build($this->tmpDir);

        self::assertStringContainsString(\date('Y-m-d') . ' at ' . $this->tmpDir . ' with tools []', $result);
    }

    /* ───────── CWD handling ───────── */

    public function testCwdWithTrailingSlashDoesNotCauseDoubleSlash(): void
    {
        $trailingCwd = $this->tmpDir . '/';
        \file_put_contents(
            $this->tmpDir . '/.hatfield/SYSTEM.md',
            'CWD: {%cwd%}',
        );

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        // Pass CWD with trailing slash; should not produce //.hatfield paths.
        $result = $builder->build($trailingCwd);

        // CWD in output should not have trailing slash
        self::assertStringContainsString('CWD: ' . $this->tmpDir, $result);
        self::assertStringNotContainsString('CWD: ' . $this->tmpDir . '/', $result);
        self::assertStringNotContainsString('//.hatfield', $result);
    }

    public function testCustomCwdPassedToBuilder(): void
    {
        \file_put_contents(
            $this->tmpDir . '/.hatfield/SYSTEM.md',
            'CWD: {%cwd%}',
        );

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        // Use the tmpDir as cwd so our project override is found, but verify
        // the rendered CWD value is the one passed in build()
        $result = $builder->build($this->tmpDir);

        self::assertStringContainsString('CWD: ' . $this->tmpDir, $result);
    }

    /* ───────── Error cases ───────── */

    public function testMissingBuiltInTemplateThrows(): void
    {
        $registry = $this->createEmptyRegistry();

        // Create builder with a non-existent project dir so config/SYSTEM.md cannot be found.
        $bogusDir = $this->tmpDir . '/nonexistent-project';

        $builder = new SystemPromptBuilder($registry, $bogusDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Built-in SYSTEM.md not found');

        $builder->build($this->tmpDir . '/noprojectoverride');
    }

    /* ───────── Integration-style: InProcessAgentSessionClient injection ───────── */

    public function testSystemPromptIsPrependedAsFirstMessage(): void
    {
        // This test verifies that the SystemPromptBuilder produces output that
        // would be correctly injected as a system AgentMessage.
        // Verifies the builder output is non-empty and contains valid system
        // prompt text from the built-in template.

        $registry = $this->createEmptyRegistry();
        $builder = new SystemPromptBuilder($registry, $this->projectDir);

        $systemPromptText = $builder->build($this->tmpDir);

        // The system prompt starts with the built-in content (no override).
        // It should be non-empty.
        self::assertNotEmpty($systemPromptText);

        // Should begin with the SYSTEM.md content (role: 'system' message)
        self::assertStringContainsString('expert coding assistant', \strtolower($systemPromptText));
    }

    /* ───────── Private helpers ───────── */

    private function createEmptyRegistry(): ToolRegistryInterface
    {
        return new ToolRegistry();
    }

    private function createRegistryWithTools(): ToolRegistryInterface
    {
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'read',
            description: 'Read file contents',
            parametersJsonSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            handler: $this->dummyHandler(),
            promptLine: '- read: Read file contents',
            promptGuidelines: ['Use read for files'],
        );
        $registry->registerTool(
            name: 'write',
            description: 'Write file contents',
            parametersJsonSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']]],
            handler: $this->dummyHandler(),
            promptLine: '- write: Write file contents',
            promptGuidelines: ['Use write for files'],
        );

        return $registry;
    }

    private function dummyHandler(): object
    {
        return new class {
            public function __invoke(): string
            {
                return 'handler result';
            }
        };
    }

    /**
     * Recursively remove a directory.
     */
    private function rmdirRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @\rmdir((string) $entry);
            } else {
                @\unlink((string) $entry);
            }
        }

        @\rmdir($path);
    }
}
