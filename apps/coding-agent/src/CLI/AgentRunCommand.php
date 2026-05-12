<?php

declare(strict_types=1);

namespace App\CLI;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agent:run', description: 'Start a new agent run')]
final class AgentRunCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->writeln('Agent run command - not yet implemented');

        return Command::SUCCESS;
    }
}
