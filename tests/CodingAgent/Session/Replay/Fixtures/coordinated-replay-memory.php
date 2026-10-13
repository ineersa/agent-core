<?php

declare(strict_types=1);

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\Replay\SessionReplayCoordinator;
use Monolog\Handler\TestHandler;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$count = (int) $argv[2];
$directory = $argv[1].'/'.$count;
(new Filesystem())->mkdir($directory.'/.hatfield/sessions/coordinated');
chdir($directory);
$_ENV['HATFIELD_CWD'] = $directory;
$_ENV['APP_SECRET'] = 'test-secret';
putenv('HATFIELD_CWD='.$directory);
putenv('APP_SECRET=test-secret');
$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $container = $kernel->getContainer()->get('test.service_container');
    // Capture only read summaries. Recording every compaction diagnostic would
    // turn this measurement helper itself into a growing in-memory history.
    $handler = new class extends TestHandler {
        public function handle(Monolog\LogRecord $record): bool
        {
            return 'history_index.physical_read' === $record->message && parent::handle($record);
        }
    };
    $container->get('monolog.logger')->pushHandler($handler);
    $normalizer = $container->get(EventPayloadNormalizer::class);
    $path = $directory.'/.hatfield/sessions/coordinated/events.jsonl';
    $handle = fopen($path, 'wb');
    if (false === $handle) {
        throw new RuntimeException('Cannot create isolated replay fixture.');
    }
    $seq = 1;
    $write = static function (int $turn, string $type, array $payload) use ($normalizer, $handle, &$seq): void {
        fwrite($handle, json_encode($normalizer->normalize('coordinated', $seq, $turn, $type, $payload), \JSON_THROW_ON_ERROR)."\n");
        $seq += 3;
    };
    try {
        $write(0, 'run_started', ['payload' => ['messages' => []]]);
        for ($turn = 1; $turn <= $count; ++$turn) {
            $write($turn, 'turn_advanced', ['turn_no' => $turn, 'step_id' => 'step']);
            $write($turn, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('old-', 1024)]]]]);
            $write($turn, 'context_compacted', ['trigger' => 'auto', 'continue_after_compaction' => true, 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'fixed summary']]]]]);
        }
    } finally {
        fclose($handle);
    }
    $coordinator = $container->get(SessionReplayCoordinator::class);
    $before = memory_get_usage(true);
    $reads = static function () use ($handler): array {
        $bytes = [];
        foreach ($handler->getRecords() as $record) {
            if ('history_index.physical_read' === $record->message) {
                $reason = $record->context['read_reason'];
                $bytes[$reason] = ($bytes[$reason] ?? 0) + $record->context['archive_bytes_read'];
            }
        }

        return $bytes;
    };
    $handler->clear();
    $result = $coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
    $cold = $reads();
    $afterCold = memory_get_usage(true);
    unset($result);
    $handler->clear();
    $result = $coordinator->reconstruct(RunState::queued('coordinated'), withTranscript: true);
    if (null === $result) {
        throw new RuntimeException('Known archive produced no recovered state.');
    }
    $warm = $reads();
    $handler->clear();
    $selected = $coordinator->reconstruct(RunState::queued('coordinated'), 1, true);
    if (null === $selected) {
        throw new RuntimeException('Selected replay produced no state.');
    }
    echo json_encode(['count' => $count, 'archive_bytes' => filesize($path), 'peak_bytes' => memory_get_peak_usage(true), 'before_bytes' => $before, 'after_cold_bytes' => $afterCold, 'after_warm_bytes' => memory_get_usage(true), 'dispatcher' => get_class($container->get('event_dispatcher')), 'cold' => $cold, 'warm' => $warm, 'selected' => $reads(), 'selected_sequence' => $selected->state->lastSeq, 'messages' => count($result->state->messages), 'blocks' => count($result->blocks)], \JSON_THROW_ON_ERROR)."\n";
} finally {
    $kernel->shutdown();
}
