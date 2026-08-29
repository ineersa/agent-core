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
 * Runs at priority -100 (same band as prompt templates) so real/built-in
 * registrars win on name collisions. Includes on-demand-only skills so users
 * can still invoke them explicitly even when they are omitted from the model
 * catalog.
 */
final class SkillCommandRegistrar implements SlashCommandCatalogRegistrar, SlashCommandHandler
{
    public function __construct(
        private readonly SkillDiscovery $discovery,
        private readonly SkillsContextBuilder $contextBuilder,
    ) {
    }

    public static function getPriority(): int
    {
        return -100;
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
        $skill = $this->discovery->findByCommandName(substr($command->name, \strlen('skill:')))
            ?? throw new \LogicException('Registered skill command is missing from discovery.');

        return new DispatchRuntime($this->contextBuilder->buildFor([$skill->name]));
    }
}
