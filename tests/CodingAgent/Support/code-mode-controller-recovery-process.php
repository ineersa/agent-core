<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\RedriveDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 3).'/vendor/autoload.php';
[$unused, $directory, $role] = $argv;
$setup = str_starts_with($role, 'setup');
$abandoned = str_ends_with($role, '-abandoned');
if ($setup) {
    TestDirectoryIsolation::createHatfieldTree($directory, withSessions: true);
    $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(dirname(__DIR__, 3), $directory, 'controller-recovery');
    file_put_contents($directory.'/database-env', $paths['appEnv']);
}
foreach (['HATFIELD_CWD' => $directory, 'APP_SECRET' => 'test-secret', 'HATFIELD_TEST_DATABASE_PATH' => trim(file_get_contents($directory.'/database-env'))] as $key => $value) {
    $_ENV[$key] = $value;
    putenv($key.'='.$value);
}
chdir($directory);
$kernel = new Kernel('test', false);
$kernel->boot();
$process = null;
try {
    $c = $kernel->getContainer()->get('test.service_container');
    if ($setup) {
        ($c->get(StartupDatabaseMigrator::class))();
        $root = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun execution.");');
        $child = 'restart-child-'.$root->state->runId;
        $entry = $c->get(AgentArtifactRegistry::class)->create($root->state->runId, 'restart-child', $child, 'fork', AgentArtifactKindEnum::Fork);
        $c->get(AgentChildRunDirectory::class)->register($entry);
        $childFixture = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun execution.");', runId: $child);
        $ids = [];
        foreach ([$root, $childFixture] as $fixture) {
            $run = $fixture->state->runId;
            $service = $c->get(CodeModeExecutionService::class);
            $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
            $ids[$run] = $id;
            if ($abandoned || $fixture === $childFixture) {
                $service->ready($id);
                $fixture->commit($fixture->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $fixture->state));
            }
            if ($abandoned) {
                // No terminal survives the ownership boundary, as when a blocked
                // host is terminated after stop/discard but before publication.
                $c->get(CodeModeExecutionRepository::class)->interrupt($id, $fixture === $root ? CodeModeInterruptionReasonEnum::Cancelled : CodeModeInterruptionReasonEnum::HistoryDiscarded);
            } else {
                $service->preserve($id, ['diagnostics' => 'retained before process loss'], null);
            }
        }
        file_put_contents($directory.'/scope', json_encode(['owner' => $root->state->runId, 'ids' => $ids], \JSON_THROW_ON_ERROR));
        $kernel->shutdown();
        // No shutdown event, retained PHP object, or in-memory result crosses
        // this boundary. The new process sees only committed SQL and artifacts.
        $process = new Process([\PHP_BINARY, __FILE__, $directory, $abandoned ? 'recover-abandoned' : 'recover'], env: ['HATFIELD_SESSION_ID' => false]);
        $process->setTimeout(10);
        $process->mustRun();
        echo $process->getOutput();
    } else {
        $scope = json_decode(file_get_contents($directory.'/scope'), true, flags: \JSON_THROW_ON_ERROR);
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $listener->starting(new ControllerSessionStartingEvent($scope['owner']));
        $listener->starting(new ControllerSessionStartingEvent($scope['owner']));
        $transport = $c->get('messenger.transport.run_control');
        if ([] !== $transport->getSent()) {
            throw new RuntimeException('Passive controller recovery dispatched work.');
        }
        foreach ($scope['ids'] as $run => $id) {
            $c->get(CompleteDeferredToolCallHandler::class)->redrive(new RedriveDeferredToolCall((string) $run, $id));
        }
        $cursor = 0;
        for ($round = 0; $round < 8; ++$round) {
            $sent = $transport->getSent();
            if ($cursor === count($sent)) {
                break;
            }
            for (; $cursor < count($sent); ++$cursor) {
                $envelope = $sent[$cursor];
                if ($envelope->getMessage() instanceof ToolPayloadMessage || $envelope->getMessage() instanceof DeliverBackgroundCompletionDTO) {
                    $c->get('agent.command.bus')->dispatch($envelope->with(new ReceivedStamp('run_control')));
                }
            }
        }
        $listener->starting(new ControllerSessionStartingEvent($scope['owner']));
        $evidence = [];
        foreach ($scope['ids'] as $run => $id) {
            $run = (string) $run;
            $json = $c->get(Connection::class)->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]);
            $service = $c->get(CodeModeExecutionService::class);
            $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
            $row = $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm');
            if ($abandoned) {
                $evidence[] = ['root' => $run === $scope['owner'], 'status' => $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id), 'ends' => count(array_filter($events, static fn ($e): bool => 'tool_execution_end' === $e->type)), 'acks' => count(array_filter($events, static fn ($e): bool => 'tool_call_acknowledged' === $e->type)), 'accepted' => $json, 'raw' => $row['raw_reference'], 'reason' => $row['interruption_reason'], 'batch' => $c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'), 'work' => $c->get(CodeModeExecutionRepository::class)->ownedBackgroundWork($run, null), 'sent' => count($transport->getSent()), 'llm' => count($c->get('messenger.transport.llm')->getSent())];

                continue;
            }
            $result = $c->get(CodeModeResultArtifactStore::class)->read($service->resultReference($json));
            $evidence[] = ['root' => $run === $scope['owner'], 'status' => $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id), 'ends' => count(array_filter($events, static fn ($e): bool => 'tool_execution_end' === $e->type)), 'acks' => count(array_filter($events, static fn ($e): bool => 'tool_call_acknowledged' === $e->type)), 'error' => $result->error['type'], 'raw_exists' => is_file($c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference'])))];
        }
        echo json_encode($evidence, \JSON_THROW_ON_ERROR)."\n";
    }
} finally {
    if ($process instanceof Process && $process->isRunning()) {
        $process->stop(0);
    }
    $kernel->shutdown();
}
