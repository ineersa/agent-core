<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Result of loading all prompt templates from all configured sources.
 *
 * Carries both successfully loaded templates and any non-fatal diagnostics
 * (collisions, read errors, YAML errors, missing paths). The caller decides
 * whether to surface diagnostics (e.g. to a debug UI or structured logs).
 *
 * @internal
 */
final readonly class PromptTemplateLoadResult
{
    /**
     * @param list<LoadedPromptTemplate>     $templates
     * @param list<PromptTemplateDiagnostic> $diagnostics
     */
    public function __construct(
        public array $templates,
        public array $diagnostics,
    ) {
    }
}
