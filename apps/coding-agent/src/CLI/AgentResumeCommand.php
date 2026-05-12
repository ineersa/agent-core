<?php

declare(strict_types=1);

namespace App\CLI;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agent:resume', description: 'Resume an existing agent run')]
final class AgentResumeCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->writeln('Agent resume command - not yet implemented');

        return Command::SUCCESS;
    }
}
