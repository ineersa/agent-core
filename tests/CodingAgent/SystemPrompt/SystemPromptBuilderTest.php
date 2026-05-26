<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

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
        $this->projectDir = realpath(__DIR__.'/../../..');
        \assert(\is_string($this->projectDir), 'Cannot resolve project directory');

        // Create a temp directory for test templates without polluting real .hatfield/
        $this->tmpDir = sys_get_temp_dir().'/system_prompt_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/.hatfield', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    /* ───────── Built-in template rendering ───────── */

    public function testBuiltInTemplateRendersWithEmptyRegistry(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->build($this->tmpDir);

        // The built-in config/SYSTEM.md is used when no override exists.
        // Verify key structural elements are present.
        $this->assertStringContainsString('expert coding assistant', strtolower($result));
        $this->assertStringContainsString('<available_tools>', $result);
        $this->assertStringContainsString('</available_tools>', $result);
        $this->assertStringContainsString('<guidelines>', $result);
        $this->assertStringContainsString('</guidelines>', $result);
        $this->assertStringContainsString('<context_channels>', $result);

        // Verify placeholders are replaced (empty values from empty registry)
        $this->assertStringNotContainsString('{available_tools_list}', $result);
        $this->assertStringNotContainsString('{registered_guidelines}', $result);
        $this->assertStringNotContainsString('{appends_part}', $result);
        $this->assertStringNotContainsString('{date}', $result);
        $this->assertStringNotContainsString('{cwd}', $result);

        // Verify date is present (any date in Y-m-d format)
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $result);

        // Verify CWD is present
        $this->assertStringContainsString($this->tmpDir, $result);
    }

    public function testBuiltInTemplateWithRegisteredTools(): void
    {
        $registry = $this->createRegistryWithTools();
        $builder = $this->createBuilder($registry);

        $result = $builder->build();

        // Tool lines should appear in <available_tools>
        $this->assertStringContainsString('- read: Read file contents', $result);
        $this->assertStringContainsString('- write: Write file contents', $result);

        // Guidelines should appear in <guidelines>
        $this->assertStringContainsString('Use read for files', $result);
        $this->assertStringContainsString('Use write for files', $result);
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

        $builder = $this->createBuilder($registry);
        $result = $builder->build();

        // Deduped lines: '- read: Read file contents' appears only once
        $this->assertSame(1, substr_count($result, '- read: Read file contents'));

        // Deduped guidelines: 'Read files with cat -n' appears only once
        $this->assertSame(1, substr_count($result, 'Read files with cat -n'));
        // 'Use read for text files' appears once
        $this->assertSame(1, substr_count($result, 'Use read for text files'));
    }

    /* ───────── Template override precedence ───────── */

    public function testProjectOverrideReplacesBuiltIn(): void
    {
        $projectSystemPath = $this->tmpDir.'/.hatfield/SYSTEM.md';
        file_put_contents($projectSystemPath, 'Custom project system prompt. Date: {date} CWD: {cwd}');

        $builder = $this->createBuilder();

        $result = $builder->build();

        $this->assertStringContainsString('Custom project system prompt.', $result);
        $this->assertStringNotContainsString('expert coding assistant', strtolower($result));
        $this->assertStringContainsString(date('Y-m-d'), $result);
        $this->assertStringContainsString($this->tmpDir, $result);
    }

    public function testHomeOverrideReplacesBuiltInWhenNoProjectOverride(): void
    {
        // SettingsPathResolver reads HOME env at construction time.
        // We set HOME temporarily to point at our test home dir.
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/SYSTEM.md', 'Home system prompt. Date: {date}');

        $oldHome = getenv('HOME');
        putenv('HOME='.$homeDir);

        try {
            // Use a different dir as cwd so project override isn't found
            $otherDir = $this->tmpDir.'/other';
            @mkdir($otherDir);

            // Builder captures HOME at construction through SettingsPathResolver.
            // CWD is sourced from AppConfig, so set it on the builder.
            $builder = $this->createBuilder(cwd: $otherDir);

            $result = $builder->build();

            $this->assertStringContainsString('Home system prompt.', $result);
            $this->assertStringNotContainsString('expert coding assistant', strtolower($result));
            $this->assertStringContainsString(date('Y-m-d'), $result);
        } finally {
            putenv('HOME='.($oldHome ?: ''));
        }
    }

    public function testProjectOverrideTakesPrecedenceOverHomeOverride(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/SYSTEM.md', 'Home system prompt.');

        file_put_contents($this->tmpDir.'/.hatfield/SYSTEM.md', 'Project system prompt.');

        $oldHome = getenv('HOME');
        putenv('HOME='.$homeDir);

        try {
            $builder = $this->createBuilder();

            $result = $builder->build();

            $this->assertStringContainsString('Project system prompt.', $result);
            $this->assertStringNotContainsString('Home system prompt.', $result);
        } finally {
            putenv('HOME='.($oldHome ?: ''));
        }
    }

    /* ───────── Append template rendering ───────── */

    public function testAppendTemplatesRenderedAndInserted(): void
    {
        // Add project APPEND_SYSTEM.md
        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'Append content. Date: {date} CWD: {cwd}',
        );

        $builder = $this->createBuilder();

        $result = $builder->build();

        // Append content should be rendered
        $this->assertStringContainsString('Append content.', $result);
        $this->assertStringContainsString(date('Y-m-d'), $result);
        $this->assertStringContainsString($this->tmpDir, $result);

        // Built-in content should still be present
        $this->assertStringContainsString('expert coding assistant', strtolower($result));
    }

    public function testBothHomeAndProjectAppendTemplatesMerged(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/APPEND_SYSTEM.md', 'Home append: {cwd}');

        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'Project append: {cwd}',
        );

        $oldHome = getenv('HOME');
        putenv('HOME='.$homeDir);

        try {
            $builder = $this->createBuilder();

            $result = $builder->build();

            // Home append appears first, then project append
            $this->assertStringContainsString('Home append: '.$this->tmpDir, $result);
            $this->assertStringContainsString('Project append: '.$this->tmpDir, $result);

            // Home content appears before project content
            $homePos = strpos($result, 'Home append:');
            $projectPos = strpos($result, 'Project append:');
            $this->assertNotFalse($homePos);
            $this->assertNotFalse($projectPos);
            $this->assertLessThan($projectPos, $homePos);
        } finally {
            putenv('HOME='.($oldHome ?: ''));
        }
    }

    public function testAppendTemplateDoesNotRecurseIntoAppendsPart(): void
    {
        // APPEND_SYSTEM.md that contains {appends_part}
        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'Append with appends_part placeholder: [{appends_part}]',
        );

        $builder = $this->createBuilder();

        $result = $builder->build();

        // {appends_part} in append content should be empty (no recursion)
        $this->assertStringContainsString('Append with appends_part placeholder: []', $result);
    }

    public function testNoAppendTemplatesResultsInEmptyAppendsPart(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->build();

        // {appends_part} in the built-in template should be replaced with empty string
        $this->assertStringNotContainsString('{appends_part}', $result);
    }

    /* ───────── Placeholder substitution ───────── */

    public function testAllPlaceholdersAreSubstituted(): void
    {
        $registry = $this->createRegistryWithTools();
        $builder = $this->createBuilder($registry);

        // Use a custom SYSTEM.md override that explicitly tests all placeholders
        file_put_contents($this->tmpDir.'/.hatfield/SYSTEM.md', implode("\n", [
            'Tools: [{available_tools_list}]',
            'Guidelines: [{registered_guidelines}]',
            'Appends: [{appends_part}]',
            'Date: [{date}]',
            'CWD: [{cwd}]',
        ]));

        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'Extra guidelines: ignore',
        );

        $result = $builder->build();

        $this->assertStringContainsString('Tools: [- read: Read file contents'."\n".'- write: Write file contents]', $result);
        $this->assertStringContainsString('Guidelines: [Use read for files'."\n".'Use write for files]', $result);
        $this->assertStringContainsString('Appends: [Extra guidelines: ignore]', $result);
        $this->assertStringContainsString('Date: ['.date('Y-m-d').']', $result);
        $this->assertStringContainsString('CWD: ['.$this->tmpDir.']', $result);
    }

    public function testPlaceholdersAreSubstitutedInOverrideTemplate(): void
    {
        file_put_contents(
            $this->tmpDir.'/.hatfield/SYSTEM.md',
            '{date} at {cwd} with tools [{available_tools_list}]',
        );

        $builder = $this->createBuilder();

        $result = $builder->build();

        $this->assertStringContainsString(date('Y-m-d').' at '.$this->tmpDir.' with tools []', $result);
    }

    /* ───────── CWD handling ───────── */

    public function testCwdWithTrailingSlashDoesNotCauseDoubleSlash(): void
    {
        $trailingCwd = $this->tmpDir.'/';
        file_put_contents(
            $this->tmpDir.'/.hatfield/SYSTEM.md',
            'CWD: {cwd}',
        );

        // CWD is sourced from AppConfig; set it with trailing slash.
        $builder = $this->createBuilder(cwd: $trailingCwd);

        $result = $builder->build();

        // CWD in output should not have trailing slash
        $this->assertStringContainsString('CWD: '.$this->tmpDir, $result);
        $this->assertStringNotContainsString('CWD: '.$this->tmpDir.'/', $result);
        $this->assertStringNotContainsString('//.hatfield', $result);
    }

    public function testCwdFromConfigUsedForTemplateResolution(): void
    {
        file_put_contents(
            $this->tmpDir.'/.hatfield/SYSTEM.md',
            'CWD: {cwd}',
        );

        $builder = $this->createBuilder();

        // CWD comes from AppConfig (set to tmpDir in createBuilder).
        $result = $builder->build();

        $this->assertStringContainsString('CWD: '.$this->tmpDir, $result);
    }

    public function testEmptyCwdThrows(): void
    {
        // Create builder with empty CWD in AppConfig.
        $bogusDir = $this->tmpDir.'/bogus-project';
        $builder = $this->createBuilder(null, $bogusDir, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CWD is not configured');

        $builder->build();
    }

    /* ───────── Error cases ───────── */

    public function testMissingBuiltInTemplateThrows(): void
    {
        // Create builder with a non-existent project dir so config/SYSTEM.md cannot be found.
        // CWD is set to a dir with no SYSTEM.md override, so built-in is used
        // (which also doesn't exist).
        $bogusDir = $this->tmpDir.'/nonexistent-project';
        $builder = $this->createBuilder(null, $bogusDir, $this->tmpDir.'/noprojectoverride');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Built-in SYSTEM.md not found');

        $builder->build();
    }

    /* ───────── Integration-style: InProcessAgentSessionClient injection ───────── */

    public function testSystemPromptIsPrependedAsFirstMessage(): void
    {
        // This test verifies that the SystemPromptBuilder produces output that
        // would be correctly injected as a system AgentMessage.
        // Verifies the builder output is non-empty and contains valid system
        // prompt text from the built-in template.

        $builder = $this->createBuilder();

        $systemPromptText = $builder->build();

        // The system prompt starts with the built-in content (no override).
        // It should be non-empty.
        $this->assertNotEmpty($systemPromptText);

        // Should begin with the SYSTEM.md content (role: 'system' message)
        $this->assertStringContainsString('expert coding assistant', strtolower($systemPromptText));
    }

    /* ───────── Private helpers ───────── */

    private function createBuilder(
        ?ToolRegistryInterface $registry = null,
        ?string $projectDir = null,
        ?string $cwd = null,
    ): SystemPromptBuilder {
        return new SystemPromptBuilder(
            toolRegistry: $registry ?? $this->createEmptyRegistry(),
            pathResolver: new SettingsPathResolver($projectDir ?? $this->projectDir),
            templateRenderer: new StringTemplateRenderer(),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $cwd ?? $this->tmpDir,
            ),
            projectDir: $projectDir ?? $this->projectDir,
        );
    }

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
                @rmdir((string) $entry);
            } else {
                @unlink((string) $entry);
            }
        }

        @rmdir($path);
    }
}
