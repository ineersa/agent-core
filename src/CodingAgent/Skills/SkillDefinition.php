<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

/**
 * Value object representing a discovered skill.
 *
 * Created by SkillDiscovery and held by SkillRegistry.
 * Immutable after construction.
 */
final readonly class SkillDefinition
{
    /**
     * @param string $name                   Unique skill name (from frontmatter or directory name)
     * @param string $description            Human-readable description (empty if not set in frontmatter)
     * @param string $skillFile              Absolute path to SKILL.md
     * @param string $skillDirectory         Absolute path to the skill root directory (parent of SKILL.md)
     * @param bool   $modelInvocationEnabled Whether the model can invoke this skill (disable-model-invocation in frontmatter)
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $skillFile,
        public string $skillDirectory,
        public bool $modelInvocationEnabled = true,
    ) {
    }
}
