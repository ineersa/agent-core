<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotEnvelopeDTO;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

require dirname(__DIR__, 5).'/vendor/autoload.php';
[$script, $cwd, $run, $mode] = $argv;
chdir($cwd);
foreach (['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_SECRET' => 'test-secret', 'HATFIELD_CWD' => $cwd] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$store = $container->get(ToolBatchStoreInterface::class);
$batch = $store->load($run, 1, 'tools');
$call = $batch?->calls['read-1'] ?? null;
if (null === $call) {
    throw new RuntimeException('Missing test invocation.');
}
$gate = $container->get(ToolExecutionAuthorization::class);
$claim = $gate->claim($call);
if (!is_string($claim)) {
    throw new RuntimeException('Test worker did not claim the tool.');
}
$result = new ToolCallResult($run, 1, 'tools', 1, 'terminal-result', 'read-1', 0, 'original durable tool result');
if ('suspension' === $mode) {
    $request = Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::toolCallFromPayload(['question_id' => 'question-1', 'question' => 'Continue?'], ['run_id' => $run, 'turn_no' => 1, 'step_id' => 'tools', 'tool_call_id' => 'read-1']);
    $result = new ToolCallResult($run, 1, 'tools', 1, 'human-suspension', 'read-1', 0, null, pendingHumanInput: $request);
}
if (in_array($mode, ['ready', 'suspension'], true)) {
    $gate->saveResult($call, $claim, $result);
} elseif (in_array($mode, ['orphan', 'corrupt', 'conflicting'], true)) {
    $batch = $store->load($run, 1, 'tools');
    if (null === $batch) {
        throw new RuntimeException('Missing claimed batch.');
    }
    $key = array_key_first($batch->executionAuthorizations);
    if ('corrupt' === $mode) {
        $result = new ToolCallResult($run, 1, 'tools', 1, 'terminal-result', 'wrong-call', 0, 'valid text with mismatched identity');
    }
    $batch->executionAuthorizations[$key] = ['state' => 'ResultReady', 'claim' => $claim];
    $batch->executionResults[$key] = $result;
    $path = $container->get(ToolBatchRunStoragePathsInterface::class)->resolveToolBatchesDirectory($run).'/1_'.hash('sha256', 'tools').'.json.tmp.complete';
    // Complete staging-file fixture for the process-death-before-rename boundary.
    // This proves adoption, not injection into AtomicFileWriter's rename syscall.
    (new Filesystem())->dumpFile($path, $container->get(SerializerInterface::class)->serialize(new ToolBatchSnapshotEnvelopeDTO($run, 1, 'tools', $batch), 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP], 'json_encode_options' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE]));
    if ('conflicting' === $mode) {
        $batch->executionResults[$key] = new ToolCallResult($run, 1, 'tools', 1, 'terminal-result', 'read-1', 0, 'different durable result for the same claim');
        (new Filesystem())->dumpFile($path.'.second', $container->get(SerializerInterface::class)->serialize(new ToolBatchSnapshotEnvelopeDTO($run, 1, 'tools', $batch), 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP], 'json_encode_options' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE]));
    }
}
fwrite(\STDOUT, "claim_ready\n");
fflush(\STDOUT);
if ("finish\n" !== fgets(\STDIN)) {
    throw new RuntimeException('Test worker did not receive its owned shutdown barrier.');
}
$kernel->shutdown();
