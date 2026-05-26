<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Psr\Log\LoggerInterface;

/**
 * Orchestrates skill discovery, registry construction, and context rendering
 * for injection into the initial user-context message.
 */
final readonly class SkillsContextBuilder
{
    public function __construct(
        private readonly SkillDiscovery $discovery,
        private readonly SkillsConfig $config,
        private readonly SkillContextRenderer $renderer,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Build the complete skills context for the initial user-context message.
     *
     * Returns the rendered <skills_instructions> block and any preloaded
     * <skill> blocks concatenated together. Returns empty string when
     * there are no model-invocable skills and no preloads.
     */
    public function build(): string
    {
        $discovered = $this->discovery->discover();
        $collisions = $this->discovery->getCollisions();
        $registry = new SkillRegistry($discovered, $collisions);

        $parts = [];

        // Render available skills instructions
        $available = $this->renderer->renderAvailableSkills($registry->modelInvocable());
        if ('' !== $available) {
            $parts[] = $available;
        }

        // Resolve and render preloaded skills
        foreach ($this->config->preloadSkills as $preloadName) {
            $skill = $registry->get($preloadName);
            if (null === $skill) {
                if (null !== $this->logger) {
                    $this->logger->warning('Preloaded skill not found: "{name}"', [
                        'name' => $preloadName,
                    ]);
                }
                continue;
            }

            $body = $registry->readBody($skill);
            $parts[] = $this->renderer->renderPreloadedSkill($skill, $body);
        }

        return implode("\n\n", $parts);
    }
}
