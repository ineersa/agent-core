<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Command;

use Ineersa\AgentCore\Application\Handler\RunDebugService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agent-loop:run-rebuild-hot-state', description: 'Rebuild hot prompt state for a run from event history.')]
final class AgentLoopRunRebuildHotStateCommand extends Command
{
    public function __construct(private readonly RunDebugService $runDebugService)
    {
        parent::__construct();

        $this
            ->addArgument('runId', InputArgument::REQUIRED, 'Run identifier.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON output.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = (string) $input->getArgument('runId');
        $state = $this->runDebugService->rebuildHotPromptState($runId);

        if (true === $input->getOption('json')) {
            $output->writeln($this->encodeJson($state));

            return self::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('Rebuild hot prompt state: %s', $runId));

        $io->definitionList(
            ['source' => (string) $state['source']],
            ['event_count' => (string) $state['event_count']],
            ['last_seq' => (string) $state['last_seq']],
            ['is_contiguous' => true === $state['is_contiguous'] ? 'yes' : 'no'],
            ['missing_sequences' => [] === $state['missing_sequences'] ? 'none' : implode(',', $state['missing_sequences'])],
            ['token_estimate' => (string) $state['token_estimate']],
            ['messages_count' => (string) \count($state['messages'])],
        );

        if ([] !== $state['missing_sequences']) {
            $io->warning('Sequence gaps were detected while rebuilding hot state.');
        }

        $io->success('Hot prompt state rebuild completed.');

        return self::SUCCESS;
    }

    /**
     * Encodes a payload as readable JSON for command output.
     *
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        $encoded = json_encode($payload, \JSON_PRETTY_PRINT);

        return false === $encoded ? '{}' : $encoded;
    }
}
