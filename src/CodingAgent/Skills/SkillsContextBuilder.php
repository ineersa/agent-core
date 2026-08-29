<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Runtime\Contract\SkillCatalogInterface;
use Ineersa\CodingAgent\Runtime\Contract\SkillCommand;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates skill discovery, registry construction, and context rendering
 * for injection into the initial user-context message.
 *
 * Also expands `/skill:<name>` invocations and exposes skill slash commands to
 * the TUI through {@see SkillCatalogInterface}.
 */
final readonly class SkillsContextBuilder implements SkillCatalogInterface
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
        $discovered = $this->discovery->discover();
        $collisions = $this->discovery->getCollisions();
        $registry = new SkillRegistry($discovered, extractor: $this->extractor, collisions: $collisions);

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

        $discovered = $this->discovery->discover();
        $collisions = $this->discovery->getCollisions();
        $registry = new SkillRegistry($discovered, extractor: $this->extractor, collisions: $collisions);

        $parts = [];
        $seen = [];
        foreach ($names as $preloadName) {
            if (isset($seen[$preloadName])) {
                continue;
            }
            $seen[$preloadName] = true;

            $skill = $registry->get($preloadName);
            if (null === $skill) {
                if (null !== $this->logger) {
                    $this->logger->warning('Agent skill not found for preload: "{name}"', [
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

    /**
     * @return list<SkillCommand>
     */
    public function allSkillCommands(): array
    {
        $commands = [];
        foreach ($this->discovery->discover() as $skill) {
            $name = 'skill:'.strtolower($skill->name);
            $commands[$name] ??= new SkillCommand(
                name: $name,
                description: $skill->description,
            );
        }

        return array_values($commands);
    }

    /**
     * Expand a `/skill:<name>` invocation into a full `<skill>` block.
     *
     * Unknown skills and non-skill text pass through unchanged. Optional
     * trailing arguments are appended after the skill block.
     */
    public function expandSkillCommand(string $text): string
    {
        if (0 !== strncasecmp($text, '/skill:', \strlen('/skill:'))) {
            return $text;
        }

        $rest = substr($text, \strlen('/skill:'));
        if ('' === $rest) {
            return $text;
        }

        $parts = preg_split('/\s+/', $rest, 2);
        \assert(\is_array($parts));
        $skillName = $parts[0];
        $args = isset($parts[1]) ? trim($parts[1]) : '';

        if ('' === $skillName) {
            return $text;
        }

        $discovered = $this->discovery->discover();
        $skill = null;
        foreach ($discovered as $candidate) {
            if (0 === strcasecmp($candidate->name, $skillName)) {
                $skill = $candidate;
                break;
            }
        }
        if (null === $skill) {
            return $text;
        }

        $registry = new SkillRegistry(
            $discovered,
            extractor: $this->extractor,
            collisions: $this->discovery->getCollisions(),
        );
        $body = $registry->readBody($skill);
        $skillBlock = $this->renderer->renderPreloadedSkill($skill, $body);

        return '' !== $args ? $skillBlock."\n\n".$args : $skillBlock;
    }
}
