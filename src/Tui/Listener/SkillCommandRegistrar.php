<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;

/**
 * Registers virtual slash commands for every discovered skill as `/skill:<name>`.
 *
 * Runs from InteractiveMode after the code-review command and prompt
 * templates, and after built-in and extension commands, so all of those
 * win on name collisions. Includes
 * on-demand-only skills so users can still invoke them explicitly even when
 * they are omitted from the model catalog.
 */
final class SkillCommandRegistrar implements SlashCommandCatalogRegistrar, SlashCommandHandler
{
    public function __construct(
        private readonly SkillDiscovery $discovery,
        private readonly SkillsContextBuilder $contextBuilder,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        foreach ($this->discovery->discover() as $skill) {
            $name = 'skill:'.strtolower($skill->name);
            if ($catalog->has($name)) {
                continue;
            }

            $catalog->register(
                new CommandMetadata(
                    name: $name,
                    aliases: [],
                    description: $skill->description,
                    usage: '/'.$name,
                    acceptsArguments: false,
                ),
                $this,
            );
        }
    }

    public function handle(SlashCommand $command): DispatchRuntime
    {
        $skill = $this->discovery->findByName(substr($command->name, \strlen('skill:')))
            ?? throw new \LogicException('Registered skill command is missing from discovery.');

        return new DispatchRuntime($this->contextBuilder->buildFor([$skill->name]));
    }
}
