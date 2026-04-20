<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This class implements a Symfony console command to report the health status of the agent loop. It retrieves configuration details to provide operational visibility into the agent's runtime state.
 */
#[AsCommand(name: 'agent-loop:health', description: 'Show Agent Loop bundle health and effective runtime settings.')]
final class AgentLoopHealthCommand extends Command
{
    /**
     * initializes the command with agent loop configuration.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Agent Loop health');
        $io->definitionList(
            ['runtime' => (string) ($this->config['runtime'] ?? 'unknown')],
            ['streaming' => (string) ($this->config['streaming'] ?? 'unknown')],
            ['run_log_storage' => (string) ($this->config['storage']['run_log']['flysystem_storage'] ?? 'unknown')],
            ['run_log_base_path' => (string) ($this->config['storage']['run_log']['base_path'] ?? 'unknown')],
            ['hot_prompt_backend' => (string) ($this->config['storage']['hot_prompt']['backend'] ?? 'unknown')],
            ['command_prefix' => (string) ($this->config['commands']['custom_kind_prefix'] ?? 'unknown')],
            ['event_prefix' => (string) ($this->config['events']['custom_type_prefix'] ?? 'unknown')],
        );
        $io->success('Agent Loop bundle bootstrapped.');

        return self::SUCCESS;
    }
}
