<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Cached prompt-template service.
 *
 * Provides a lazily loaded, process-lifetime-cached template catalog and
 * single-pass template expansion. TUI and runtime inject this concrete
 * service directly for catalog projection and expansion.
 *
 * @internal
 */
final class PromptTemplateService
{
    private ?PromptTemplateLoadResult $cached = null;

    public function __construct(
        private readonly PromptTemplateLoader $loader,
        private readonly PromptTemplateArgumentParser $argumentParser,
        private readonly PromptTemplateSubstitutor $substitutor,
    ) {
    }

    /**
     * @return list<LoadedPromptTemplate>
     */
    public function allPromptTemplateCommands(): array
    {
        return $this->result()->templates;
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
