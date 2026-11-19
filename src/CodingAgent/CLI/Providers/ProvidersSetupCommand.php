<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\CLI\Setup\SetupCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'providers:setup', description: 'Interactive setup for AI providers')]
final readonly class ProvidersSetupCommand
{
    public function __construct(private SetupCommand $setup)
    {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input, #[Option(description: 'Edit project settings instead of user settings')] bool $project = false): int
    {
        return $this->setup->open($io, $input, $project, 1);
    }
}
