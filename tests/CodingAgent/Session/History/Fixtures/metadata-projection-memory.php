<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$store = new CacheHistoryProjectionStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new HistoryProjector(), new RunLockManager(new LockFactory(new InMemoryStore())));
$runId = 'metadata-memory';
$events = (static function () use ($runId): Generator {
    yield new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
        'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'retained prompt']]]],
    ]);
    yield new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]);
    // Over 512 MiB of distinct transient payloads. Collecting the input cannot
    // pass the child's 128 MiB hard limit; history metadata does not use them.
    for ($seq = 3; $seq <= 8194; ++$seq) {
        yield new RunEvent($runId, $seq, 1, RunEventTypeEnum::ToolExecutionUpdate->value, [
            'delta' => str_repeat((string) ($seq % 10), 65536),
        ]);
    }
})();

$snapshot = $store->initializeFromEvents($runId, $events);
$lookup = $store->get($runId);
fwrite(\STDOUT, json_encode([
    'limit' => ini_get('memory_limit'),
    'peak_bytes' => memory_get_peak_usage(true),
    'last_seq' => $snapshot->lastSeq,
    'prompt' => $lookup->history->promptsByTurnNo[1] ?? null,
    'turns' => $lookup->history->retainedTurnNos,
], \JSON_THROW_ON_ERROR)."\n");
