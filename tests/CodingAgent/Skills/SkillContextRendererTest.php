<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Skills\SkillContextRenderer;
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
        $this->renderer = new SkillContextRenderer();
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

        self::assertStringContainsString('<skills_instructions>', $output);
        self::assertStringContainsString('<available_skills>', $output);
        self::assertStringContainsString('<name>castor</name>', $output);
        self::assertStringContainsString('<description>Runs Castor tasks</description>', $output);
        self::assertStringContainsString('<location>/home/user/.hatfield/skills/castor/SKILL.md</location>', $output);
        self::assertStringContainsString('</skills_instructions>', $output);
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

        self::assertStringContainsString('<name>castor</name>', $output);
        self::assertStringContainsString('<name>grill-me</name>', $output);
        // Both skills should appear
        self::assertSame(2, substr_count($output, '<skill>'));
    }

    public function testRenderAvailableSkillsEmptyReturnsEmptyString(): void
    {
        $output = $this->renderer->renderAvailableSkills([]);

        self::assertSame('', $output);
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

        self::assertStringContainsString('<skill name="castor"', $output);
        self::assertStringContainsString('location="/home/user/.hatfield/skills/castor/SKILL.md"', $output);
        self::assertStringContainsString('References are relative to /home/user/.hatfield/skills/castor', $output);
        self::assertStringContainsString('Use the `castor` command for tasks.', $output);
        self::assertStringContainsString('</skill>', $output);
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

        self::assertStringContainsString('test&amp;co', $output);
        self::assertStringContainsString('&quot;tests&quot;', $output);
        self::assertStringContainsString('&lt;scripts&gt;', $output);
        self::assertStringContainsString('some &amp; co', $output);
        // Ensure raw characters are NOT present
        self::assertStringNotContainsString('test&co', $output);
        self::assertStringNotContainsString('<scripts>', $output);
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

        self::assertStringContainsString('foo&amp;bar', $output);
        self::assertStringContainsString('&quot;quotes&quot;', $output);
    }
}
