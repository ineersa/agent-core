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

/** Routes review requests through main; the skill owns orchestration, not the TUI. */
final readonly class CodeReviewCommandRegistrar implements SlashCommandCatalogRegistrar, SlashCommandHandler
{
    public function __construct(
        private SkillDiscovery $discovery,
        private SkillsContextBuilder $contextBuilder,
    ) {
    }

    public function registerCatalog(SlashCommandCatalog $catalog): void
    {
        if (null === $this->discovery->findByName('code-review')) {
            return;
        }

        $catalog->register(new CommandMetadata(
            name: 'code-review',
            description: 'Review a committed change with independent reviewer, auditor and verifier agents',
            usage: '/code-review [request]',
            acceptsArguments: true,
        ), $this);
    }

    public function handle(SlashCommand $command): DispatchRuntime
    {
        return new DispatchRuntime(
            $this->contextBuilder->buildFor(['code-review'])
            ."\n\nRun the code-review skill for this request (empty means the current change):\n"
            .$command->args,
        );
    }
}
