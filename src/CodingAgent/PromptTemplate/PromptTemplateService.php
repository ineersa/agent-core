<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCatalogInterface;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCommand;

/**
 * Cached prompt-template service.
 *
 * Provides a lazily loaded, process-lifetime-cached template catalog and
 * single-pass template expansion. Implements PromptTemplateCatalogInterface
 * so TUI can register virtual slash commands through the deptrac-safe
 * Runtime\Contract boundary.
 *
 * There is no PromptTemplateExpanderInterface — the runtime layer injects
 * this concrete service directly for expansion.
 *
 * @internal
 */
final class PromptTemplateService implements PromptTemplateCatalogInterface
{
    private ?PromptTemplateLoadResult $cached = null;

    public function __construct(
        private readonly PromptTemplateLoader $loader,
        private readonly PromptTemplateArgumentParser $argumentParser,
        private readonly PromptTemplateSubstitutor $substitutor,
    ) {
    }

    /**
     * @return list<PromptTemplateCommand>
     */
    public function allPromptTemplateCommands(): array
    {
        return array_map(
            static fn (LoadedPromptTemplate $t): PromptTemplateCommand => new PromptTemplateCommand(
                name: $t->name,
                description: $t->description,
            ),
            $this->result()->templates,
        );
    }

    /**
     * Expand a prompt-template invocation in user text.
     *
     * If text starts with "/" and matches a known template name, the template
     * body is expanded with arguments. Otherwise the text is returned unchanged.
     *
     * Expansion is single-pass — if a template body starts with "/other", it
     * is NOT expanded again.
     *
     * @param string $text The user text to potentially expand (e.g. "/review foo bar")
     *
     * @return string the expanded prompt or the original text
     */
    public function expandPromptTemplate(string $text): string
    {
        if (!str_starts_with($text, '/')) {
            return $text;
        }

        if (1 !== preg_match('#^/([^\s]+)(?:\s+([\s\S]*))?$#', $text, $matches)) {
            return $text;
        }

        $templateName = $matches[1];
        $argsString = $matches[2] ?? '';

        foreach ($this->result()->templates as $template) {
            if ($template->name === $templateName) {
                $args = $this->argumentParser->parse($argsString);

                return $this->substitutor->substitute($template->content, $args);
            }
        }

        return $text;
    }

    private function result(): PromptTemplateLoadResult
    {
        return $this->cached ??= $this->loader->load();
    }
}
