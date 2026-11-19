<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Setup\SetupCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'extensions:setup', description: 'Install and configure Hatfield extensions')]
final readonly class ExtensionsSetupCommand
{
    public function __construct(private SetupCommand $setup)
    {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input, #[Option(description: 'Edit user settings instead of project settings')] bool $user = false): int
    {
        return $this->setup->open($io, $input, !$user, 2);
    }
}
