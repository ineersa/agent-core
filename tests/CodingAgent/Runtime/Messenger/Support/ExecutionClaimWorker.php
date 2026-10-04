<?php

declare(strict_types=1);

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\ApplicationMigrationExecutor;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

require dirname(__DIR__, 5).'/vendor/autoload.php';
[$script, $cwd, $database, $run, $kind, $mode] = $argv;
chdir($cwd);
foreach (['APP_ENV' => 'test', 'APP_DEBUG' => '0', 'APP_SECRET' => 'test-secret', 'HATFIELD_CWD' => $cwd] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$connection = $container->get('doctrine.dbal.connection_factory')->createConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
(new ApplicationMigrationExecutor($connection, new NullLogger()))();
$paths = $container->get(ToolBatchRunStoragePathsInterface::class);
$store = new DoctrineExecutionOperationStore($connection, $paths, new Filesystem(), $container->get('hatfield.controller.session_owner.lock_factory'));
$request = match ($kind) {
    'llm' => new ExecuteLlmStep($run, 1, 'claim', 1, 'original', 'tools'),
    'compaction' => new ExecuteCompactionStep($run, 1, 'claim', 1, 'original', 'test/model', [], [], [], 0, 0, 0, 0, 'manual'),
    'shell' => new ExecuteShellToolCall($run, 1, 'claim', 1, 'call', 'printf safe', true),
};
$events = $container->get(PreparedTransitionEventStoreInterface::class);
$events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
$transition = $events->verifiedPendingTransition($run);
if (null === $transition) {
    throw new RuntimeException('Missing test transition.');
}
$authorization = $store->arm($request, $transition);
$events->finalizeVerifiedTransition($run, $transition->identity);
$serializer = new PhpSerializer();
$envelope = $serializer->decode($serializer->encode(new Envelope($store->requestReference($request, $authorization), [$authorization])));
$reference = $envelope->getMessage();
$stamp = $envelope->last(ExecutionAuthorizationStamp::class);
if (!$reference instanceof ExecutionRequest || !$stamp instanceof ExecutionAuthorizationStamp) {
    throw new RuntimeException('Invalid test delivery.');
}
$claim = $store->claim($reference, $stamp);
if (!is_string($claim)) {
    throw new RuntimeException('Test worker did not claim execution.');
}
$request = $store->resolveRequest($reference, $stamp, $claim);
file_put_contents($cwd.'/invocation-'.$kind.'.txt', 'one invocation');
if ('seal' === $mode || 'corrupt' === $mode) {
    $identity = [$request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey()];
    $result = match ($kind) {
        'llm' => new LlmStepResult(...$identity),
        'compaction' => new CompactionStepResult(...[...$identity, 'original summary', null, [], 0, 0, 0, 0, 'manual']),
        'shell' => new ToolCallResult(...[...$identity, 'call', 0, ['content' => [['type' => 'text', 'text' => 'original output']]]]),
    };
    $connection->executeStatement("CREATE TRIGGER fail_result BEFORE UPDATE OF state ON execution_operation WHEN NEW.state = 'ResultReady' BEGIN SELECT RAISE(FAIL, 'injected publication failure'); END");
    try {
        $store->saveResult($request, $stamp, $claim, $result);
        throw new RuntimeException('Expected publication fault did not occur.');
    } catch (Doctrine\DBAL\Exception\DriverException $exception) {
        // Intentional test fault after real immutable-file publication.
        fwrite(\STDERR, 'Injected result-row failure: '.$exception::class."\n");
    } finally {
        $connection->executeStatement('DROP TRIGGER fail_result');
    }
    if ('corrupt' === $mode) {
        $path = dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$reference->effectId.'/'.hash('sha256', $claim).'.result';
        (new Filesystem())->dumpFile($path, '{"schema":2}');
    }
}
echo "claim_ready\n";
flush();
// The pipe is a positive barrier. EOF also permits deterministic teardown.
fgets(\STDIN);
$connection->close();
$kernel->shutdown();
