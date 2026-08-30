<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;

/**
 * Registers virtual slash commands for every prompt template in the catalog.
 *
 * Runs at priority -100 so real/built-in registrars (SessionCommandRegistrar,
 * CopyCommandRegistrar, etc.) win on name collisions. When a template name
 * matches an already-registered command, the template is silently skipped.
 *
 * Template commands are registered into the process-scoped
 * {@see SlashCommandCatalog} exactly once per process; their handler is
 * stateless and returns {@see DispatchRuntime} with the original slash
 * text; expansion happens later at the in-process runtime boundary
 * (PT-02), not in the TUI.
 */
final class PromptTemplateCommandRegistrar implements SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly PromptTemplateService $promptTemplates,
    ) {
    }

    public static function getPriority(): int
    {
        return -100;
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        $handler = new class implements SlashCommandHandler {
            public function handle(SlashCommand $command): DispatchRuntime
            {
                return new DispatchRuntime($command->originalText);
            }
        };

        foreach ($this->promptTemplates->allPromptTemplateCommands() as $template) {
            // Real/built-in commands win — skip if already registered.
            if ($catalog->has($template->name)) {
                continue;
            }

            $catalog->register(
                new CommandMetadata(
                    name: $template->name,
                    aliases: [],
                    description: $template->description,
                    usage: '/'.$template->name.' <args>',
                    acceptsArguments: true,
                ),
                $handler,
            );
        }
    }
}
