<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates skill discovery, model-invocable filtering, skill body reads,
 * and context rendering for injection into the initial user-context message.
 */
final readonly class SkillsContextBuilder
{
    public function __construct(
        private readonly SkillDiscovery $discovery,
        private readonly SkillsConfig $config,
        private readonly SkillContextRenderer $renderer,
        private readonly MarkdownFrontmatterExtractor $extractor,
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
        $modelInvocable = array_values(array_filter(
            $this->discovery->discover(),
            static fn (SkillDefinition $s): bool => $s->modelInvocationEnabled && '' !== $s->description,
        ));

        $parts = [];

        // Render available skills instructions
        $available = $this->renderer->renderAvailableSkills($modelInvocable);
        if ('' !== $available) {
            $parts[] = $available;
        }

        // Resolve and render preloaded skills
        foreach ($this->config->preloadSkills as $preloadName) {
            $skill = $this->discovery->findByName($preloadName);
            if (null === $skill) {
                if (null !== $this->logger) {
                    $this->logger->warning('Preloaded skill not found: "{name}"', [
                        'name' => $preloadName,
                    ]);
                }
                continue;
            }

            $body = $this->readBody($skill);
            if (null === $body) {
                continue;
            }
            $parts[] = $this->renderer->renderPreloadedSkill($skill, $body);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Render preloaded skill bodies for the given skill names (agent frontmatter).
     *
     * Unlike {@see build()}, this does not include the <available_skills> catalog —
     * only full <skill> blocks for resolved names, in request order.
     *
     * @param list<string> $skillNames
     */
    public function buildFor(array $skillNames): string
    {
        $names = [];
        foreach ($skillNames as $name) {
            if (!\is_string($name)) {
                continue;
            }
            $trimmed = trim($name);
            if ('' === $trimmed) {
                continue;
            }
            $names[] = $trimmed;
        }

        if ([] === $names) {
            return '';
        }

        $parts = [];
        $seen = [];
        foreach ($names as $preloadName) {
            $key = strtolower($preloadName);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $skill = $this->discovery->findByName($preloadName);
            if (null === $skill) {
                if (null !== $this->logger) {
                    $this->logger->warning('Agent skill not found for preload: "{name}"', [
                        'name' => $preloadName,
                    ]);
                }
                continue;
            }

            $body = $this->readBody($skill);
            if (null === $body) {
                continue;
            }
            $parts[] = $this->renderer->renderPreloadedSkill($skill, $body);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Read the skill body (SKILL.md with frontmatter stripped).
     *
     * Returns null and logs when the file cannot be read, so callers skip the
     * <skill> block instead of rendering it empty.
     */
    private function readBody(SkillDefinition $skill): ?string
    {
        $content = is_file($skill->skillFile) ? file_get_contents($skill->skillFile) : false;

        if (false === $content) {
            if (null !== $this->logger) {
                $this->logger->warning('Skipping preloaded skill with unreadable SKILL.md', [
                    'component' => 'skills.context',
                    'event_type' => 'skill_body_unreadable',
                    'skill_name' => $skill->name,
                    'path' => $skill->skillFile,
                ]);
            }

            return null;
        }

        return $this->extractor->extract($content)['body'];
    }
}
