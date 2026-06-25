<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Context;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;

/**
 * Renders agent definition catalog entries into XML-ish blocks for user-context.
 */
final readonly class AgentContextRenderer
{
    /**
     * Render the <agents_instructions> + <available_agents> block.
     *
     * @param list<AgentDefinitionDTO> $agents enabled definitions (caller filters)
     */
    public function renderAvailableAgents(array $agents): string
    {
        if ([] === $agents) {
            return '';
        }

        usort(
            $agents,
            static fn (AgentDefinitionDTO $a, AgentDefinitionDTO $b): int => strcmp($a->name, $b->name),
        );

        $parts = [];
        $parts[] = '<agents_instructions>';
        $parts[] = 'The following agent definitions can be launched via the subagent tool.';
        $parts[] = 'Use the listed agent name and a clear task description. Do not guess agent names.';
        $parts[] = 'Agent bodies and full instructions are not included here; only name and description are shown.';
        $parts[] = '';
        $parts[] = '<available_agents>';

        foreach ($agents as $agent) {
            $parts[] = '  <agent>';
            $parts[] = '    <name>'.$this->escapeXml($agent->name).'</name>';
            $parts[] = '    <description>'.$this->escapeXml($agent->description).'</description>';
            $parts[] = '  </agent>';
        }

        $parts[] = '</available_agents>';
        $parts[] = '</agents_instructions>';

        return implode("\n", $parts);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
