<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Ineersa\CodingAgent\SystemPrompt\LlmProxyDeterministicPromptMode;

/**
 * Renders skills context into XML-ish blocks for the user-context message.
 *
 * Two rendering modes:
 *   1. renderAvailableSkills — produces <skills_instructions> with <available_skills> listing
 *   2. renderPreloadedSkill — produces a single <skill> block with full body
 */
final readonly class SkillContextRenderer
{
    public function __construct(
        private LlmProxyDeterministicPromptMode $llmProxyDeterministicPromptMode,
    ) {
    }

    /**
     * Render the <skills_instructions> + <available_skills> block.
     *
     * @param list<SkillDefinition> $modelInvocableSkills
     */
    public function renderAvailableSkills(array $modelInvocableSkills): string
    {
        if ([] === $modelInvocableSkills) {
            return '';
        }

        $parts = [];
        $parts[] = '<skills_instructions>';
        $parts[] = 'The following skills provide specialized instructions for specific tasks.';
        $parts[] = 'Use the read tool to load a skill\'s file when the task matches its description.';
        $parts[] = 'When a skill file references a relative path, resolve it against the skill directory (parent of SKILL.md / dirname of the path) and use that absolute path in tool commands.';
        $parts[] = '';
        $parts[] = '<available_skills>';

        foreach ($modelInvocableSkills as $skill) {
            $parts[] = '  <skill>';
            $parts[] = '    <name>'.$this->escapeXml($skill->name).'</name>';
            $parts[] = '    <description>'.$this->escapeXml($skill->description).'</description>';
            $parts[] = '    <location>'.$this->escapeXml($this->locationForPrompt($skill)).'</location>';
            $parts[] = '  </skill>';
        }

        $parts[] = '</available_skills>';
        $parts[] = '</skills_instructions>';

        return implode("\n", $parts);
    }

    /**
     * Render a preloaded skill body as a <skill> block.
     */
    public function renderPreloadedSkill(SkillDefinition $skill, string $body): string
    {
        $escapedName = $this->escapeXml($skill->name);
        $escapedLocation = $this->escapeXml($this->locationForPrompt($skill));

        $parts = [];
        $parts[] = \sprintf('<skill name="%s" location="%s">', $escapedName, $escapedLocation);
        $parts[] = 'References are relative to '.$this->escapeXml($this->skillDirectoryForPrompt($skill)).'.';
        $parts[] = '';
        $parts[] = $body;
        $parts[] = '</skill>';

        return implode("\n", $parts);
    }


    private function locationForPrompt(SkillDefinition $skill): string
    {
        if (!$this->llmProxyDeterministicPromptMode->enabled()) {
            return $skill->skillFile;
        }

        return $this->llmProxyDeterministicPromptMode->fixedCwd().'/.agents/skills/'.$skill->name.'/SKILL.md';
    }

    private function skillDirectoryForPrompt(SkillDefinition $skill): string
    {
        if (!$this->llmProxyDeterministicPromptMode->enabled()) {
            return $skill->skillDirectory;
        }

        return $this->llmProxyDeterministicPromptMode->fixedCwd().'/.agents/skills/'.$skill->name;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
