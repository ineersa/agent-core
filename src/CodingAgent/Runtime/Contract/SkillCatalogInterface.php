<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Catalog contract for skill slash commands, exposed through Runtime\Contract
 * so TuiListener can depend on it without depending on AppSkills
 * (deptrac-safe boundary).
 *
 * Implemented by SkillsContextBuilder.
 */
interface SkillCatalogInterface
{
    /**
     * Return all discovered skills as TUI-safe slash-command DTOs.
     *
     * Includes on-demand-only skills (`disable-model-invocation: true`) so users
     * can still invoke them via `/skill:<name>`.
     *
     * @return list<SkillCommand>
     */
    public function allSkillCommands(): array;
}
