<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentsContextRenderer.
 *
 * Covers:
 * - Single file renders correct XML structure
 * - Multiple files rendered in order
 * - Empty list returns empty string
 * - Exact XML structure with path attributes
 *
 * @group system-prompt
 */
final class AgentsContextRendererTest extends TestCase
{
    private AgentsContextRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AgentsContextRenderer();
    }

    public function testRendersSingleFile(): void
    {
        $discovered = [
            ['path' => '/home/user/project/AGENTS.md', 'content' => "Project instructions:\n- Do X\n- Do Y"],
        ];

        $result = $this->renderer->render($discovered);

        $expected = <<<'XML'
<project_context>
Project-specific instructions and guidelines:

<project_instructions path="/home/user/project/AGENTS.md">
Project instructions:
- Do X
- Do Y
</project_instructions>
</project_context>
XML;

        self::assertSame($expected, $result);
        self::assertStringContainsString('<project_context>', $result);
        self::assertStringContainsString('</project_context>', $result);
        self::assertStringContainsString('<project_instructions path="/home/user/project/AGENTS.md">', $result);
    }

    public function testRendersMultipleFilesInOrder(): void
    {
        $discovered = [
            ['path' => '/home/user/.hatfield/AGENTS.md', 'content' => 'Global context'],
            ['path' => '/home/user/project/AGENTS.md', 'content' => 'Project context'],
        ];

        $result = $this->renderer->render($discovered);

        // Both blocks present
        self::assertStringContainsString('Global context', $result);
        self::assertStringContainsString('Project context', $result);

        // First file's block appears before second file's block
        $globalPos = strpos($result, 'Global context');
        $projectPos = strpos($result, 'Project context');
        self::assertNotFalse($globalPos);
        self::assertNotFalse($projectPos);
        self::assertLessThan($projectPos, $globalPos);

        // Both path attributes present
        self::assertStringContainsString('/home/user/.hatfield/AGENTS.md', $result);
        self::assertStringContainsString('/home/user/project/AGENTS.md', $result);
    }

    public function testEmptyListReturnsEmptyString(): void
    {
        $result = $this->renderer->render([]);

        self::assertSame('', $result);
    }

    public function testXmlStructure(): void
    {
        $discovered = [
            ['path' => '/path/to/AGENTS.md', 'content' => "Instructions:\n1. First\n2. Second"],
        ];

        $result = $this->renderer->render($discovered);

        // Starts with project_context opening tag
        self::assertStringStartsWith('<project_context>', $result);

        // Ends with project_context closing tag
        self::assertStringEndsWith('</project_context>', $result);

        // Contains the description line
        self::assertStringContainsString('Project-specific instructions and guidelines:', $result);

        // Contains the project_instructions block with path attribute
        self::assertStringContainsString('<project_instructions path="/path/to/AGENTS.md">', $result);
        self::assertStringContainsString('</project_instructions>', $result);

        // Content is inside the block
        self::assertStringContainsString('Instructions:', $result);
    }

    public function testContentWithXmlSpecialCharsIsEscaped(): void
    {
        $discovered = [
            ['path' => '/path/to/AGENTS.md', 'content' => 'Use <b>bold</b> & "quoted" text'],
        ];

        $result = $this->renderer->render($discovered);

        // XML special chars should be escaped in output
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $result);
        self::assertStringContainsString('&amp;', $result);
        self::assertStringContainsString('&quot;quoted&quot;', $result);
        // Raw special chars should NOT appear
        self::assertStringNotContainsString('<b>', $result);
    }

    public function testPathWithSpecialCharsIsEscaped(): void
    {
        $discovered = [
            ['path' => '/path/to/special & chars/AGENTS.md', 'content' => 'content'],
        ];

        $result = $this->renderer->render($discovered);

        // Path attribute should have escaped &
        self::assertStringContainsString('path="/path/to/special &amp; chars/AGENTS.md"', $result);
    }
}
