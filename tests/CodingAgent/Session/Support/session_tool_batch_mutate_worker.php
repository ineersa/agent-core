<?php

declare(strict_types=1);

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$autoload = $argv[1] ?? null;
$callId = $argv[2] ?? null;
if (!is_string($autoload) || !is_file($autoload) || !is_string($callId) || '' === $callId) {
    fwrite(\STDERR, "usage: php session_tool_batch_mutate_worker.php <autoload> <callId>\n");
    exit(2);
}

require $autoload;

$base = getenv('HATFIELD_SESSIONS_BASE') ?: '';
if ('' === $base) {
    fwrite(\STDERR, "HATFIELD_SESSIONS_BASE required\n");
    exit(2);
}

$paths = new class($base) implements ToolBatchRunStoragePathsInterface {
    public function __construct(private readonly string $base)
    {
    }

    public function resolveToolBatchesDirectory(string $runId): string
    {
        return $this->base.'/'.$runId.'/runtime/tool-batches';
    }
};

$store = new SessionToolBatchStore($paths, new LockFactory(new FlockStore()), new NullLogger());

$store->mutate('run-par', 1, 'step-1', static function (?array $current) use ($callId): ToolBatchStoreMutation {
    if (!is_array($current)) {
        throw new RuntimeException('missing batch');
    }
    $next = $current;
    $next['result_data'][$callId] = ['ok' => true];
    $next['pending_queue'] = array_values(array_filter(
        $next['pending_queue'],
        static fn (string $id): bool => $id !== $callId,
    ));
    if (2 === count($next['result_data'])) {
        $next['finalized'] = true;
    }

    return new ToolBatchStoreMutation(null, $next);
});
