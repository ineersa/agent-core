<?php

declare(strict_types=1);

use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$count = (int) $argv[2];
$directory = $argv[1].'/'.$count;
(new Filesystem())->mkdir($directory.'/.hatfield/sessions/indexed');
$_ENV['HATFIELD_CWD'] = $directory;
$_ENV['APP_SECRET'] = 'test-secret';
putenv('HATFIELD_CWD='.$directory);
putenv('APP_SECRET=test-secret');
$kernel = new Kernel('test', false);
$kernel->boot();
try {
    $container = $kernel->getContainer()->get('test.service_container');
    $normalizer = $container->get(EventPayloadNormalizer::class);
    $path = $directory.'/.hatfield/sessions/indexed/events.jsonl';
    $handle = fopen($path, 'wb');
    if (false === $handle) {
        throw new RuntimeException('Cannot create isolated memory fixture.');
    }
    try {
        for ($seq = 1; $seq <= $count; ++$seq) {
            $turn = (int) ceil($seq / 2);
            $line = json_encode($normalizer->normalize('indexed', $seq, $turn, 0 === $seq % 2 ? 'turn_advanced' : 'agent_command_queued', ['turn_no' => $turn, 'text' => str_repeat('x', 4096)]), \JSON_THROW_ON_ERROR)."\n";
            fwrite($handle, $line);
            unset($line);
        }
    } finally {
        fclose($handle);
    }
    $logger = new TestLogger();
    $log = new JsonlRunEventLog($normalizer, $container->get(LockFactory::class), $container->get(RunSequenceAllocatorInterface::class), new EventLogMaxSeqBootstrapReader(), $logger);
    $records = 0;
    foreach ($log->indexedLines($path, 'indexed', 1, \PHP_INT_MAX) as $line) {
        ++$records;
        unset($line);
    }
    $bytesRead = array_sum(array_map(static fn (array $record): int => $record['context']['archive_bytes_read'], array_filter($logger->records, static fn (array $record): bool => 'history_index.physical_read' === $record['message'])));
    echo json_encode(['count' => $count, 'records' => $records, 'archive_bytes' => filesize($path), 'index_bytes' => filesize(dirname($path).'/history-index.sqlite'), 'archive_bytes_read' => $bytesRead, 'peak_bytes' => memory_get_peak_usage(true)], \JSON_THROW_ON_ERROR)."\n";
} finally {
    $kernel->shutdown();
}
