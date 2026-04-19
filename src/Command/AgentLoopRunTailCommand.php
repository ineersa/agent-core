<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Command;

use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Application\Handler\RunDebugService;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This command prints the latest persisted events for a run.
 */
#[AsCommand(name: 'agent-loop:run-tail', description: 'Tail the latest persisted events for a run.')]
final class AgentLoopRunTailCommand extends Command
{
    /**
     * Injects tail-read dependencies.
     */
    public function __construct(
        private readonly RunDebugService $runDebugService,
        private readonly RunEventSerializer $runEventSerializer,
    ) {
        parent::__construct();

        $this
            ->addArgument('runId', InputArgument::REQUIRED, 'Run identifier.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many latest events to print.', '25')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON output.')
        ;
    }

    /**
     * Prints a tail window of recent run events.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = (string) $input->getArgument('runId');
        $limit = (int) $input->getOption('limit');

        $tail = $this->runDebugService->tail($runId, $limit);

        /** @var list<array<string, mixed>> $normalizedEvents */
        $normalizedEvents = array_map(
            fn (RunEvent $event): array => $this->runEventSerializer->normalizeRunEvent($event),
            $tail['events'],
        );

        $payload = [
            'run_id' => $tail['run_id'],
            'source' => $tail['source'],
            'total_events' => $tail['total_events'],
            'limit' => $tail['limit'],
            'events' => $normalizedEvents,
        ];

        if (true === $input->getOption('json')) {
            $output->writeln($this->encodeJson($payload));

            return self::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('Run tail: %s', $runId));

        $io->definitionList(
            ['source' => (string) $tail['source']],
            ['total_events' => (string) $tail['total_events']],
            ['limit' => (string) $tail['limit']],
            ['returned' => (string) \count($normalizedEvents)],
        );

        if ([] === $normalizedEvents) {
            $io->warning('No events were found for this run.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($normalizedEvents as $event) {
            $rows[] = [
                (string) $event['seq'],
                (string) $event['turn_no'],
                (string) $event['type'],
                (string) $event['ts'],
                $this->payloadPreview($event['payload']),
            ];
        }

        $io->table(['seq', 'turn_no', 'type', 'ts', 'payload'], $rows);

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

    /**
     * Builds a compact payload preview for tabular output.
     */
    private function payloadPreview(mixed $payload): string
    {
        $encoded = json_encode($payload);
        if (false === $encoded) {
            return '{}';
        }

        if (\strlen($encoded) <= 120) {
            return $encoded;
        }

        return substr($encoded, 0, 117).'...';
    }
}
