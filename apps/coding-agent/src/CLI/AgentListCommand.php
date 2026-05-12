<?php

declare(strict_types=1);

namespace App\CLI;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agent:list', description: 'List existing agent runs')]
final class AgentListCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Agent list command - not yet implemented');

        return Command::SUCCESS;
    }
}
