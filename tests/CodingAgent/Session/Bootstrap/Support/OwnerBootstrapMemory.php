<?php

declare(strict_types=1);

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Application\Message\AttachRun;
use Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Monolog\Handler\TestHandler;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__, 5).'/vendor/autoload.php';
$count = (int) $argv[2];
$directory = $argv[1].'/'.$count;
(new Filesystem())->mkdir($directory.'/.hatfield');
chdir($directory);
$_ENV['HATFIELD_CWD'] = $directory;
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CONSUMER_STDOUT_EVENTS'] = $_SERVER['HATFIELD_CONSUMER_STDOUT_EVENTS'] = '0';
putenv('HATFIELD_CWD='.$directory);
putenv('APP_SECRET=test-secret');
putenv('HATFIELD_CONSUMER_STDOUT_EVENTS=0');
$kernel = new Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $handler = new class extends TestHandler {
        public function handle(Monolog\LogRecord $record): bool
        {
            return 'history_index.physical_read' === $record->message && parent::handle($record);
        }
    };
    $container->get('monolog.logger')->pushHandler($handler);
    $sessions = $container->get(HatfieldSessionStore::class);
    $run = $sessions->createSession('owner bootstrap memory');
    $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
    (new Filesystem())->mkdir(dirname($path));
    $normalizer = $container->get(EventPayloadNormalizer::class);
    $stream = fopen($path, 'wb');
    if (false === $stream) {
        throw new RuntimeException('Cannot create isolated owner archive.');
    }
    $seq = 1;
    $write = static function (int $turn, string $type, array $payload) use ($normalizer, $stream, $run, &$seq): void {
        fwrite($stream, json_encode($normalizer->normalize($run, $seq, $turn, $type, $payload), \JSON_THROW_ON_ERROR)."\n");
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
        fclose($stream);
    }
    $archiveBytes = filesize($path);
    $container->get(ActiveRunContextInterface::class)->release($run);
    $handler->clear();
    $container->get(SessionMaintenanceHandler::class)->attach(new AttachRun($run, [], 'memory-attach'));
    $available = null;
    foreach ($container->get(InMemoryRuntimeEventSink::class)->drain($run) as $event) {
        if (RuntimeEventTypeEnum::BootstrapAvailable->value === $event->type) {
            $available = $event->payload;
        }
    }
    if (null === $available) {
        throw new RuntimeException('Owner did not seal a bootstrap.');
    }
    $reads = [];
    foreach ($handler->getRecords() as $record) {
        $reason = $record->context['read_reason'];
        $reads[$reason] = ($reads[$reason] ?? 0) + $record->context['archive_bytes_read'];
    }
    echo json_encode(['count' => $count, 'archive_bytes' => $archiveBytes, 'peak_bytes' => memory_get_peak_usage(true), 'indexed_reads' => $reads,
        'spool_bytes' => $available['bytes'], 'spool_records' => $available['records'], 'canonical_seq' => $available['canonical_seq'], 'end_offset' => $available['end_offset']], \JSON_THROW_ON_ERROR)."\n";
} finally {
    $kernel->shutdown();
}
