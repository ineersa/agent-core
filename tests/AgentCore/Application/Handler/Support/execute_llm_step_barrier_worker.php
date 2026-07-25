<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;

$autoload = $argv[1] ?? null;
$workerId = $argv[2] ?? null;
$gatePath = $argv[3] ?? null;
$enteredDir = $argv[4] ?? null;

if (!is_string($autoload) || !is_file($autoload)
    || !is_string($workerId) || '' === $workerId
    || !is_string($gatePath) || '' === $gatePath
    || !is_string($enteredDir) || '' === $enteredDir
) {
    fwrite(\STDERR, "usage: php execute_llm_step_barrier_worker.php <autoload> <workerId> <gatePath> <enteredDir>\n");
    exit(2);
}

require $autoload;

$enteredMarker = rtrim($enteredDir, '/').'/'.$workerId.'.entered';
$gateHandle = fopen($gatePath, 'c+b');
if (false === $gateHandle) {
    fwrite(\STDERR, "Failed to open gate\n");
    exit(2);
}

$platform = new class($workerId, $enteredMarker, $gateHandle) implements PlatformInterface {
    /**
     * @param resource $gateHandle
     */
    public function __construct(
        private readonly string $workerId,
        private readonly string $enteredMarker,
        private $gateHandle,
    ) {
    }

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        if (false === file_put_contents($this->enteredMarker, $this->workerId, \LOCK_EX)) {
            throw new RuntimeException('Failed to write entered marker for '.$this->workerId);
        }

        if (!flock($this->gateHandle, \LOCK_SH)) {
            throw new RuntimeException('Failed to acquire shared gate for '.$this->workerId);
        }

        try {
            return new PlatformInvocationResult(
                assistantMessage: new AssistantMessage(new Text('ok-'.$this->workerId)),
                usage: [],
                stopReason: 'stop',
            );
        } finally {
            flock($this->gateHandle, \LOCK_UN);
        }
    }
};

$bus = new TestMessageBus();
$worker = new ExecuteLlmStepWorker($platform, $bus);

try {
    $worker(new ExecuteLlmStep(
        runId: 'run-barrier-'.$workerId,
        turnNo: 1,
        stepId: 'step-'.$workerId,
        attempt: 1,
        idempotencyKey: 'key-'.$workerId,
        contextRef: 'ctx-'.$workerId,
        toolsRef: 'tools-'.$workerId,
        model: 'test/model',
    ));
} finally {
    fclose($gateHandle);
}

if (1 !== count($bus->messages)) {
    fwrite(\STDERR, "Expected exactly one dispatched LlmStepResult\n");
    exit(3);
}

exit(0);
