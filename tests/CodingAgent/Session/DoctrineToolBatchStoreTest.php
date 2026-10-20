<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\CodingAgent\Session\DoctrineToolBatchStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class DoctrineToolBatchStoreTest extends IsolatedKernelTestCase
{
    public function testPreparationReadsEachBatchOnceAndPreservesUnchangedEnvelopes(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $calls = [
            new ExecuteToolCall('structural', 1, 'tools', 1, 'a', 'a', 'echo', [], 0),
            new ExecuteToolCall('structural', 1, 'tools', 1, 'b', 'b', 'echo', [], 1),
            new ExecuteToolCall('structural', 1, 'tools', 1, 'c', 'c', 'echo', [], 2),
        ];
        $register = new RegisterToolBatchDTO('structural', 1, 'tools', $calls, ['a' => 0, 'b' => 1, 'c' => 2], [], ['a' => true, 'b' => true, 'c' => true], 3);
        $store->prepareChanges([$register], new VerifiedTransitionDTO('register', ['run_id' => 'structural', 'actions' => [$register]]))();
        $encoded = new PhpSerializer()->encode(new Envelope(new ToolCallResult('structural', 1, 'tools', 1, 'a-result', 'a', 0, 'first')));
        $encoded['headers']['retained-envelope-header'] = 'original';
        $connection->update('tool_batch_schedule', ['results_json' => json_encode(['a' => $encoded], \JSON_THROW_ON_ERROR)], ['run_id' => 'structural']);
        $before = $connection->fetchAssociative('SELECT * FROM tool_batch_schedule WHERE run_id = ?', ['structural']);
        $this->assertIsArray($before);
        $actions = [
            new FinalizeToolBatchDTO('structural', 1, 'tools', [], ['c' => true], [], false, new ToolCallResult('structural', 1, 'tools', 1, 'b-result', 'b', 1, 'second')),
            new FinalizeToolBatchDTO('structural', 1, 'tools', [], [], [], true, new ToolCallResult('structural', 1, 'tools', 1, 'c-result', 'c', 2, 'third')),
        ];
        $counted = $this->createMock(Connection::class);
        $counted->expects($this->once())->method('fetchAssociative')->willReturnCallback($connection->fetchAssociative(...));
        $counted->expects($this->once())->method('fetchOne')->willReturnCallback($connection->fetchOne(...));
        $counted->expects($this->once())->method('update')->willReturnCallback(function (string $table, array $data, array $key) use ($connection): int {
            $this->assertEqualsCanonicalizing(['applied_transition', 'finalized', 'in_flight_json', 'results_json'], array_keys($data));

            return $connection->update($table, $data, $key);
        });
        $counted->expects($this->never())->method('insert');
        new DoctrineToolBatchStore($counted)->prepareChanges($actions, new VerifiedTransitionDTO('finish', ['run_id' => 'structural', 'actions' => $actions]))();
        $after = $connection->fetchAssociative('SELECT * FROM tool_batch_schedule WHERE run_id = ?', ['structural']);
        $this->assertIsArray($after);
        $this->assertSame($before['calls_json'], $after['calls_json']);
        $this->assertSame($encoded, json_decode($after['results_json'], true, 512, \JSON_THROW_ON_ERROR)['a']);
        $batch = $store->load('structural', 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame(['a', 'b', 'c'], array_keys($batch->results));
        $this->assertTrue($batch->finalized);
        $store->prepareChanges($actions, new VerifiedTransitionDTO('finish', ['run_id' => 'structural', 'actions' => $actions]))();
        $this->assertEquals($batch, $store->load('structural', 1, 'tools'));
    }

    public function testHumanAnswerRewritesOnlyRevisedCallAndRedeliveryDoesNotAdvanceAttempt(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $first = new ExecuteToolCall('answer', 1, 'tools', 1, 'a', 'a', 'echo', [], 0);
        $second = new ExecuteToolCall('answer', 1, 'tools', 1, 'b', 'b', 'echo', [], 1);
        $register = new RegisterToolBatchDTO('answer', 1, 'tools', [$first, $second], ['a' => 0, 'b' => 1], [], ['b' => true], 2);
        $store->prepareChanges([$register], new VerifiedTransitionDTO('register', ['run_id' => 'answer', 'actions' => [$register]]))();
        $calls = json_decode($connection->fetchOne('SELECT calls_json FROM tool_batch_schedule WHERE run_id = ?', ['answer']), true, 512, \JSON_THROW_ON_ERROR);
        $calls['b']['headers']['retained'] = 'unchanged';
        $connection->update('tool_batch_schedule', ['calls_json' => json_encode($calls, \JSON_THROW_ON_ERROR)], ['run_id' => 'answer']);
        $answer = new ToolCallHumanInputAnswerDTO('q', 'allow', ['tool_call_id' => 'a'], ['question_id' => 'q']);
        $action = new FinalizeToolBatchDTO('answer', 1, 'tools', [], ['a' => true, 'b' => true], [], false, revisedCallId: 'a', answer: $answer);
        $transition = new VerifiedTransitionDTO('answered', ['run_id' => 'answer', 'actions' => [$action]]);
        $store->prepareChanges([$action], $transition)();
        $store->prepareChanges([$action], $transition)();
        $batch = $store->load('answer', 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame(2, $batch->calls['a']->attempt());
        $this->assertEquals($answer, $batch->calls['a']->humanInputAnswer);
        $stored = json_decode($connection->fetchOne('SELECT calls_json FROM tool_batch_schedule WHERE run_id = ?', ['answer']), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame($calls['b'], $stored['b']);
        $redrive = new FinalizeToolBatchDTO('answer', 1, 'tools', [], ['a' => true, 'b' => true], [], false, revisedCallId: 'a');
        $store->prepareChanges([$redrive], new VerifiedTransitionDTO('redrive', ['run_id' => 'answer', 'actions' => [$redrive]]))();
        $redriven = $store->load('answer', 1, 'tools');
        $this->assertNotNull($redriven);
        $this->assertNull($redriven->calls['a']->humanInputAnswer);
        $this->assertSame(2, $redriven->calls['a']->attempt());
        $this->assertEquals($second, $redriven->calls['b']);
    }

    public function testPreparationStillRejectsCorruptUnchangedSibling(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $call = new ExecuteToolCall('corrupt', 1, 'tools', 1, 'key', 'a', 'echo', [], 0);
        $register = new RegisterToolBatchDTO('corrupt', 1, 'tools', [$call], ['a' => 0], [], ['a' => true], 1);
        $store->prepareChanges([$register], new VerifiedTransitionDTO('register', ['run_id' => 'corrupt', 'actions' => [$register]]))();
        $wrong = new ToolCallResult('another-run', 1, 'tools', 1, 'result', 'a', 0, 'wrong identity');
        $connection->update('tool_batch_schedule', ['results_json' => json_encode(['a' => new PhpSerializer()->encode(new Envelope($wrong))], \JSON_THROW_ON_ERROR)], ['run_id' => 'corrupt']);
        $action = new FinalizeToolBatchDTO('corrupt', 1, 'tools', [], [], [], true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stored tool batch result identity is invalid.');
        $store->prepareChanges([$action], new VerifiedTransitionDTO('finish', ['run_id' => 'corrupt', 'actions' => [$action]]));
    }

    public function testQueuedCallWaitsForCapacityAndMetadataRollbackIsAtomic(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('sql scheduling');
        $first = new ExecuteToolCall($run, 1, 'tools', 1, 'key-a', 'call-a', 'echo', ['command' => 'a'], 0);
        $second = new ExecuteToolCall($run, 1, 'tools', 1, 'key-b', 'call-b', 'echo', ['command' => 'b'], 1);
        $register = new RegisterToolBatchDTO($run, 1, 'tools', [$first, $second], ['call-a' => 0, 'call-b' => 1], ['call-b'], ['call-a' => true], 1);
        $mailbox = new EnqueueCommandDTO(new PendingCommand($run, 'follow_up', 'mailbox-admit', ['text' => 'keep']));
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $batches = $container->get(ToolBatchStoreInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$register, $mailbox]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $plan = new TransitionPlan($run, $pending, [$register, $mailbox], [], []);
        $failingCommands = $this->createStub(CommandStoreInterface::class);
        $failingCommands->method('prepareEnqueue')->willReturn(static function (): bool {
            throw new \RuntimeException('Injected mailbox failure.');
        });
        $coordinator = new LocalMetadataCoordinator($container->get(ApplicationDbTransactionInterface::class), $batches, $failingCommands);
        try {
            $coordinator->apply($plan);
            $this->fail('Mailbox failure must roll back batch registration.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mailbox failure.', $exception->getMessage());
        }
        $this->assertNull($batches->load($run, 1, 'tools'));
        $this->assertFalse($commands->has($run, 'mailbox-admit'));
        $this->assertNotNull($events->verifiedPendingTransition($run));

        $coordinator = $container->get(LocalMetadataCoordinator::class);
        $coordinator->apply($plan);
        $events->finalizeVerifiedTransition($run, $pending);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertEquals(['call-a' => $first, 'call-b' => $second], $batch->calls);
        $this->assertSame(['call-a' => true], $batch->inFlight);
        $this->assertSame(['call-b'], $batch->pendingQueue);
        $this->assertTrue($commands->has($run, 'mailbox-admit'));

        $complete = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: ['call-b' => true], awaitingHumanInput: [], finalized: false);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$complete]]);
        $next = $events->verifiedPendingTransition($run);
        $this->assertNotNull($next);
        $coordinator->apply(new TransitionPlan($run, $next, [$complete], [], []));
        $events->finalizeVerifiedTransition($run, $next);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame([], $batch->pendingQueue);
        $this->assertSame(['call-b' => true], $batch->inFlight);
        $this->assertEquals($second, $batch->calls['call-b']);
    }
}
