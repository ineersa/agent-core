<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Tests\SystemPrompt\LlmProxyDeterministicPromptTestSupport;
use Ineersa\CodingAgent\Skills\SkillDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkillContextRenderer.
 *
 * @group skills
 */
final class SkillContextRendererTest extends TestCase
{
    private SkillContextRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SkillContextRenderer(LlmProxyDeterministicPromptTestSupport::disabledMode());
    }

    /* ───────── renderAvailableSkills ───────── */

    public function testRenderAvailableSkillsSingle(): void
    {
        $skills = [
            new SkillDefinition(
                name: 'castor',
                description: 'Runs Castor tasks',
                skillFile: '/home/user/.hatfield/skills/castor/SKILL.md',
                skillDirectory: '/home/user/.hatfield/skills/castor',
            ),
        ];

        $output = $this->renderer->renderAvailableSkills($skills);

        $this->assertStringContainsString('<skills_instructions>', $output);
        $this->assertStringContainsString('<available_skills>', $output);
        $this->assertStringContainsString('<name>castor</name>', $output);
        $this->assertStringContainsString('<description>Runs Castor tasks</description>', $output);
        $this->assertStringContainsString('<location>/home/user/.hatfield/skills/castor/SKILL.md</location>', $output);
        $this->assertStringContainsString('</skills_instructions>', $output);
    }

    public function testRenderAvailableSkillsMultiple(): void
    {
        $skills = [
            new SkillDefinition(
                name: 'castor',
                description: 'Runs Castor tasks',
                skillFile: '/a/SKILL.md',
                skillDirectory: '/a',
            ),
            new SkillDefinition(
                name: 'grill-me',
                description: 'Interview user on plans',
                skillFile: '/b/SKILL.md',
                skillDirectory: '/b',
            ),
        ];

        $output = $this->renderer->renderAvailableSkills($skills);

        $this->assertStringContainsString('<name>castor</name>', $output);
        $this->assertStringContainsString('<name>grill-me</name>', $output);
        // Both skills should appear
        $this->assertSame(2, substr_count($output, '<skill>'));
    }

    public function testRenderAvailableSkillsEmptyReturnsEmptyString(): void
    {
        $output = $this->renderer->renderAvailableSkills([]);

        $this->assertSame('', $output);
    }

    /* ───────── renderPreloadedSkill ───────── */

    public function testRenderPreloadedSkill(): void
    {
        $skill = new SkillDefinition(
            name: 'castor',
            description: 'Runs Castor tasks',
            skillFile: '/home/user/.hatfield/skills/castor/SKILL.md',
            skillDirectory: '/home/user/.hatfield/skills/castor',
        );

        $body = "# Castor\n\nUse the `castor` command for tasks.";

        $output = $this->renderer->renderPreloadedSkill($skill, $body);

        $this->assertStringContainsString('<skill name="castor"', $output);
        $this->assertStringContainsString('location="/home/user/.hatfield/skills/castor/SKILL.md"', $output);
        $this->assertStringContainsString('References are relative to /home/user/.hatfield/skills/castor', $output);
        $this->assertStringContainsString('Use the `castor` command for tasks.', $output);
        $this->assertStringContainsString('</skill>', $output);
    }

    /* ───────── XML escaping ───────── */

    public function testXmlEscapingInDescriptionAndPath(): void
    {
        $skills = [
            new SkillDefinition(
                name: 'test&co',
                description: 'Runs "tests" for <scripts>',
                skillFile: '/path/to/some & co/SKILL.md',
                skillDirectory: '/path/to',
            ),
        ];

        $output = $this->renderer->renderAvailableSkills($skills);

        $this->assertStringContainsString('test&amp;co', $output);
        $this->assertStringContainsString('&quot;tests&quot;', $output);
        $this->assertStringContainsString('&lt;scripts&gt;', $output);
        $this->assertStringContainsString('some &amp; co', $output);
        // Ensure raw characters are NOT present
        $this->assertStringNotContainsString('test&co', $output);
        $this->assertStringNotContainsString('<scripts>', $output);
    }

    public function testPreloadXmlEscaping(): void
    {
        $skill = new SkillDefinition(
            name: 'foo&bar',
            description: '',
            skillFile: '/path/with "quotes"/SKILL.md',
            skillDirectory: '/path/with "quotes"',
        );

        $body = 'plain body';

        $output = $this->renderer->renderPreloadedSkill($skill, $body);

        $this->assertStringContainsString('foo&amp;bar', $output);
        $this->assertStringContainsString('&quot;quotes&quot;', $output);
    }
}
