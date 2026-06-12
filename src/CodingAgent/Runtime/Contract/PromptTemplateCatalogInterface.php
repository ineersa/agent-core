<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Catalog contract for prompt templates, exposed through Runtime\Contract
 * so TuiListener can depend on it without depending on AppPromptTemplate
 * (deptrac-safe boundary).
 *
 * Implemented by PromptTemplateService.
 */
interface PromptTemplateCatalogInterface
{
    /**
     * Return all loaded prompt templates as TUI-safe command DTOs.
     *
     * @return list<PromptTemplateCommand>
     */
    public function allPromptTemplateCommands(): array;
}
