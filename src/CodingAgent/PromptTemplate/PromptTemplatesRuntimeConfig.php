<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Mutable per-invocation overrides for prompt-template loading.
 *
 * Populated by AgentCommand from CLI options during process startup
 * and forwarded to controller subprocesses when needed.
 *
 * @internal
 */
final class PromptTemplatesRuntimeConfig
{
    /** @var list<string> */
    public array $promptTemplatePaths = [];

    public bool $noPromptTemplates = false;

    /**
     * Build CLI arguments that can be forwarded to a subprocess.
     *
     * Preserves repeatable --prompt-template order for deterministic
     * loading when the controller child process runs.
     *
     * @return list<string>
     */
    public function controllerArgs(): array
    {
        $args = [];
        if ($this->noPromptTemplates) {
            $args[] = '--no-prompt-templates';
        }
        foreach ($this->promptTemplatePaths as $path) {
            $args[] = '--prompt-template='.$path;
        }

        return $args;
    }
}
