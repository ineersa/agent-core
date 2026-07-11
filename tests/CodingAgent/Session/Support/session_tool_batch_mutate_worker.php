<?php

declare(strict_types=1);

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
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

$gatePath = getenv('HATFIELD_TOOL_BATCH_MUTATE_GATE') ?: '';
$gateHandle = null;
if ('' !== $gatePath) {
    $readyMarkerPath = $gatePath.'.'.$callId.'.ready';
    if (false === file_put_contents($readyMarkerPath, 'ready', \LOCK_EX)) {
        fwrite(\STDERR, "Failed to write ready marker\n");
        exit(2);
    }

    $gateHandle = fopen($gatePath, 'c+b');
    if (false === $gateHandle) {
        fwrite(\STDERR, "Failed to open mutate gate\n");
        exit(2);
    }

    if (!flock($gateHandle, \LOCK_SH)) {
        fclose($gateHandle);
        fwrite(\STDERR, "Failed to acquire shared gate lock\n");
        exit(2);
    }
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

try {
    $store->mutate('run-par', 1, 'step-1', static function (?ToolBatchStateDTO $current) use ($callId): ToolBatchStoreMutation {
        if (!$current instanceof ToolBatchStateDTO) {
            throw new RuntimeException('missing batch');
        }

        $current->results[$callId] = new ToolCallResult(
            runId: 'run-par',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: hash('sha256', 'result-'.$callId),
            toolCallId: $callId,
            orderIndex: 'c1' === $callId ? 0 : 1,
            result: ['ok' => true],
            isError: false,
            error: null,
        );
        $current->pendingQueue = array_values(array_filter(
            $current->pendingQueue,
            static fn (string $id): bool => $id !== $callId,
        ));
        if (2 === count($current->results)) {
            $current->finalized = true;
        }

        return new ToolBatchStoreMutation(null, $current);
    });
} finally {
    if (is_resource($gateHandle)) {
        flock($gateHandle, \LOCK_UN);
        fclose($gateHandle);
    }
}
