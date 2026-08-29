<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\SkillCatalogInterface;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;

/**
 * Registers virtual slash commands for every discovered skill as `/skill:<name>`.
 *
 * Runs at priority -100 (same band as prompt templates) so real/built-in
 * registrars win on name collisions. Includes on-demand-only skills so users
 * can still invoke them explicitly even when they are omitted from the model
 * catalog. Expansion of `/skill:<name>` happens later at the in-process
 * runtime boundary, not in the TUI.
 */
final class SkillCommandRegistrar implements SlashCommandCatalogRegistrar
{
    public function __construct(
        private readonly SkillCatalogInterface $catalog,
    ) {
    }

    public static function getPriority(): int
    {
        return -100;
    }

    public function registerCatalog(SlashCommandCatalog $commandCatalog): void
    {
        $handler = new class implements SlashCommandHandler {
            public function handle(SlashCommand $command): DispatchRuntime
            {
                return new DispatchRuntime($command->originalText);
            }
        };

        foreach ($this->catalog->allSkillCommands() as $skill) {
            if ($commandCatalog->has($skill->name)) {
                continue;
            }

            $commandCatalog->register(
                new CommandMetadata(
                    name: $skill->name,
                    aliases: [],
                    description: $skill->description,
                    usage: '/'.$skill->name.' [args]',
                    acceptsArguments: true,
                ),
                $handler,
            );
        }
    }
}
