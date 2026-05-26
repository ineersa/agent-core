<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

/**
 * Mutable runtime configuration for skill discovery and preloading.
 *
 * Populated by AgentCommand after CLI option parsing, then read lazily
 * by SkillDiscovery on the first discover() call.
 *
 * Defaults: auto-discovery enabled, no additional paths, no preloads.
 */
final class SkillsConfig
{
    public function __construct(
        public bool $noSkills = false,
        /** @var list<string> */
        public array $skillsPaths = [],
        /** @var list<string> */
        public array $preloadSkills = [],
    ) {
    }
}
