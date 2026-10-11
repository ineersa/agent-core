<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';

chdir($argv[1]);
$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CWD'] = $argv[1];
putenv('HATFIELD_CWD='.$argv[1]);
$kernel = new Ineersa\CodingAgent\Kernel('test', false);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    // Use the durable store under the streaming decorator. The configured
    // PreparedTransitionEventStoreInterface already streams to stdout.
    $store = $container->get(Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore::class);
    $pending = $store->verifiedPendingTransition($argv[2]);
    if (null === $pending || $pending->identity !== $argv[3]) {
        throw new RuntimeException('Verified transition missing before cold finalization.');
    }
    $sink = new Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink();
    $stream = new Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore(
        $store,
        $container->get(Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper::class),
        $sink,
        true,
        $container->get('event_dispatcher'),
    );
    $stream->finalizeVerifiedTransition($argv[2], $argv[3]);
    $emitted = iterator_to_array($sink->drain($argv[2]));
    fwrite(\STDOUT, json_encode([
        'count' => count($emitted),
        'seqs' => array_map(static fn ($event): int => $event->seq, $emitted),
        'types' => array_map(static fn ($event): string => $event->type, $emitted),
        'latest' => $store->latestSequenceFor($argv[2]),
        'range' => array_map(static fn ($event): int => $event->seq, iterator_to_array($store->rangeFor($argv[2], 1, \PHP_INT_MAX))),
    ], \JSON_THROW_ON_ERROR)."\n");
} finally {
    $kernel->shutdown();
}
