<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Psr\Log\LoggerInterface;

/**
 * Holds discovered skills and provides lookup, filtering, and body reading.
 *
 * Collision diagnostics track skipped skill directories when a name
 * collision occurs (first-discovered wins).
 */
final class SkillRegistry
{
    /** @var array<string, SkillDefinition> name → definition */
    private array $skills = [];

    /** @var list<array{winner: string, ignored: string, name: string}> */
    private array $collisions = [];

    /**
     * @param list<SkillDefinition>                                      $skills
     * @param list<array{winner: string, ignored: string, name: string}> $collisions
     */
    public function __construct(
        array $skills,
        array $collisions = [],
        private ?LoggerInterface $logger = null,
    ) {
        foreach ($skills as $skill) {
            $this->skills[$skill->name] = $skill;
        }
        $this->collisions = $collisions;
    }

    public function get(string $name): ?SkillDefinition
    {
        return $this->skills[$name] ?? null;
    }

    /**
     * @return list<SkillDefinition> All registered skills
     */
    public function all(): array
    {
        return array_values($this->skills);
    }

    /**
     * @return list<SkillDefinition> Skills with modelInvocationEnabled=true and non-empty description
     */
    public function modelInvocable(): array
    {
        return array_values(
            array_filter(
                $this->skills,
                static fn (SkillDefinition $s): bool => $s->modelInvocationEnabled && '' !== $s->description,
            ),
        );
    }

    /**
     * @return list<array{winner: string, ignored: string, name: string}>
     */
    public function collisions(): array
    {
        return $this->collisions;
    }

    /**
     * Read and return the skill body (SKILL.md with frontmatter stripped).
     */
    public function readBody(SkillDefinition $skill): string
    {
        if (!is_file($skill->skillFile)) {
            if (null !== $this->logger) {
                $this->logger->warning('Skill file not found: {path}', ['path' => $skill->skillFile]);
            }

            return '';
        }

        $content = file_get_contents($skill->skillFile);

        if (false === $content) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed to read skill body: {path}', ['path' => $skill->skillFile]);
            }

            return '';
        }

        return SkillDiscovery::stripFrontmatter($content);
    }
}
