<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

final readonly class CompactHeaderSnapshot
{
    /**
     * @param list<string>               $prompts    Prompt-template command names without leading slash
     * @param list<string>               $skills     Skill names without skill: prefix
     * @param list<string>               $agentNames Enabled agent names
     * @param list<McpServerHeaderEntry> $mcpServers
     */
    public function __construct(
        public array $prompts = [],
        public array $skills = [],
        public int $agentCount = 0,
        public array $agentNames = [],
        public array $mcpServers = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->prompts
            && [] === $this->skills
            && 0 === $this->agentCount
            && [] === $this->agentNames
            && [] === $this->mcpServers;
    }

    public function equals(self $other): bool
    {
        if ($this->prompts !== $other->prompts
            || $this->skills !== $other->skills
            || $this->agentCount !== $other->agentCount
            || $this->agentNames !== $other->agentNames
            || \count($this->mcpServers) !== \count($other->mcpServers)) {
            return false;
        }

        foreach ($this->mcpServers as $i => $entry) {
            if (!$entry->equals($other->mcpServers[$i])) {
                return false;
            }
        }

        return true;
    }
}
