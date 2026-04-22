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
 * Replays persisted run events after a given sequence cursor.
 */
#[AsCommand(name: 'agent-loop:run-replay', description: 'Replay run events after a given sequence number.')]
final class AgentLoopRunReplayCommand extends Command
{
    public function __construct(
        private readonly RunDebugService $runDebugService,
        private readonly RunEventSerializer $runEventSerializer,
    ) {
        parent::__construct();

        $this
            ->addArgument('runId', InputArgument::REQUIRED, 'Run identifier.')
            ->addOption('after-seq', null, InputOption::VALUE_REQUIRED, 'Replay events with seq > after-seq.', '0')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum events to output.', '200')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON output.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = (string) $input->getArgument('runId');
        $afterSeq = (int) $input->getOption('after-seq');
        $limit = (int) $input->getOption('limit');

        $replay = $this->runDebugService->replayAfter($runId, $afterSeq, $limit);

        /** @var list<array<string, mixed>> $normalizedEvents */
        $normalizedEvents = array_map(
            fn (RunEvent $event): array => $this->runEventSerializer->normalizeRunEvent($event),
            $replay['events'],
        );

        $payload = [
            'run_id' => $replay['run_id'],
            'source' => $replay['source'],
            'after_seq' => $replay['after_seq'],
            'total_events' => $replay['total_events'],
            'resync_required' => $replay['resync_required'],
            'missing_sequences' => $replay['missing_sequences'],
            'events' => $normalizedEvents,
        ];

        if (true === $input->getOption('json')) {
            $output->writeln($this->encodeJson($payload));

            return self::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('Run replay: %s', $runId));

        $io->definitionList(
            ['source' => (string) $replay['source']],
            ['after_seq' => (string) $replay['after_seq']],
            ['total_events' => (string) $replay['total_events']],
            ['returned' => (string) \count($normalizedEvents)],
            ['resync_required' => true === $replay['resync_required'] ? 'yes' : 'no'],
            ['missing_sequences' => [] === $replay['missing_sequences'] ? 'none' : implode(',', $replay['missing_sequences'])],
        );

        if ([] === $normalizedEvents) {
            $io->warning('No replay events matched this cursor.');

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
