<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolBatchCoordinationHandler;
use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ToolBatchCoordinationHandlerTest extends PerMethodIsolatedKernelTestCase
{
    public static function interruptionBoundaries(): iterable
    {
        yield 'after append' => [false];
        yield 'after batch finalization' => [true];
    }

    #[DataProvider('interruptionBoundaries')]
    public function testPartialSiblingPreparationAndRecoveryKeepOriginalResult(bool $finalizeBeforeRecovery): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('prepared tool collection');
        $collector = $container->get(ToolBatchCollector::class);
        $gate = $container->get(ToolExecutionAuthorization::class);
        $store = $container->get(ToolBatchStoreInterface::class);
        $transitions = $container->get(PreparedTransitionEventStoreInterface::class);
        $handler = $container->get(ToolCallResultHandler::class);
        $calls = [$this->call($run, 'a', 0), $this->call($run, 'b', 1)];
        $collector->registerExpectedBatch($run, 1, 'tools', $calls);
        $results = [];
        foreach ($calls as $call) {
            $gate->arm($call);
            $claim = $gate->claim($call);
            $this->assertIsString($claim);
            $result = new ToolCallResult($run, 1, 'tools', 1, $call->idempotencyKey(), $call->toolCallId, $call->orderIndex, 'original '.$call->toolCallId);
            $gate->saveResult($call, $claim, $result);
            $results[] = $result;
        }
        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, activeStepId: 'tools', pendingToolCalls: ['a' => false, 'b' => false]);
        $before = $store->load($run, 1, 'tools');
        $prepared = $handler->handle($results[0], $state);
        // Discard the prepared transition as a failure before intent publication.
        $this->assertEquals($before, $store->load($run, 1, 'tools'));
        $this->assertNull($transitions->verifiedPendingTransition($run));
        $this->assertEquals($results[0], $gate->claim($calls[0]));
        $redelivery = $handler->handle($results[0], $state);
        $this->assertSame('tool_execution_end', $redelivery->events[0]->type);
        $this->assertSame($prepared->events[0]->payload, $redelivery->events[0]->payload);
        $this->stage($run, $redelivery, $gate->prepareDisposition($results[0], 'Consumed'));
        if ($finalizeBeforeRecovery) {
            $container->get(ToolBatchCoordinationHandler::class)($redelivery->postCommitActions[0]);
            $this->assertSame('original a', $store->load($run, 1, 'tools')->results['a']->result);
        }
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $batch = $store->load($run, 1, 'tools');
        $this->assertSame(['a'], array_keys($batch->results));
        $this->assertFalse($batch->finalized);
        $this->assertSame(['b' => true], $batch->inFlight);
        $this->assertTrue($gate->isDisposed($results[0]));
        $this->assertNull($transitions->verifiedPendingTransition($run));
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $state = $redelivery->nextState;
        $completed = $handler->handle($results[1], $state);
        $this->stage($run, $completed, $gate->prepareDisposition($results[1], 'Consumed'));
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $batch = $store->load($run, 1, 'tools');
        $this->assertTrue($batch->finalized);
        $this->assertSame(['original a', 'original b'], array_map(static fn (ToolCallResult $result): string => $result->result, array_values($batch->results)));
        $this->assertTrue($gate->isDisposed($results[1]));
        $this->assertSame([], $handler->handle($results[1], $completed->nextState)->events);
        $types = [];
        foreach ($transitions->rangeFor($run, 1, \PHP_INT_MAX) as $event) {
            $types[] = $event->type;
        }
        $this->assertSame(['tool_execution_end', 'tool_execution_end', 'tool_batch_committed'], $types);
    }

    public function testUnsupportedLaterActionCannotPartiallyFinalizeBatch(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('invalid batch coordination plan');
        $collector = $container->get(ToolBatchCollector::class);
        $store = $container->get(ToolBatchStoreInterface::class);
        $transitions = $container->get(PreparedTransitionEventStoreInterface::class);
        $collector->registerExpectedBatch($run, 1, 'tools', [$this->call($run, 'a', 0)]);
        $before = $store->load($run, 1, 'tools');
        $prepared = $collector->prepareCollect(new ToolCallResult($run, 1, 'tools', 1, 'invocation-a', 'a', 0, 'original'));
        $this->assertNotNull($prepared->action);
        $transitions->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$prepared->action, new \stdClass()]]);
        try {
            $container->get(PendingTransitionRecovery::class)->recover($run);
            $this->fail('The complete action plan must validate before any batch write.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unsupported action', $exception->getMessage());
        }
        $this->assertEquals($before, $store->load($run, 1, 'tools'));
        $this->assertNotNull($transitions->verifiedPendingTransition($run));
    }

    public function testDependentSequentialCallIsArmedOnlyAfterPreparedDelta(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('prepared sequential dispatch');
        $collector = $container->get(ToolBatchCollector::class);
        $gate = $container->get(ToolExecutionAuthorization::class);
        $store = $container->get(ToolBatchStoreInterface::class);
        $first = new ExecuteToolCall($run, 1, 'tools', 1, 'first', 'a', 'read', ['path' => 'a'], 0);
        $second = new ExecuteToolCall($run, 1, 'tools', 1, 'second', 'b', 'read', ['path' => 'b'], 1);
        $this->assertSame([$first], $collector->registerExpectedBatch($run, 1, 'tools', [$first, $second]));
        $gate->arm($first);
        $claim = $gate->claim($first);
        $this->assertIsString($claim);
        $result = new ToolCallResult($run, 1, 'tools', 1, 'first', 'a', 0, 'original first');
        $gate->saveResult($first, $claim, $result);
        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, activeStepId: 'tools', pendingToolCalls: ['a' => false, 'b' => false]);
        $decision = $container->get(ToolCallResultHandler::class)->handle($result, $state);
        $this->assertEquals([$second], $decision->postCommitEffects);
        $this->assertSame(['b'], $store->load($run, 1, 'tools')->pendingQueue);
        $this->assertSame(['a' => true], $store->load($run, 1, 'tools')->inFlight);
        try {
            $gate->claim($second);
            $this->fail('Preparation must not authorize a dependent tool.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Tool execution has no owner authorization.', $exception->getMessage());
        }
        $this->stage($run, $decision, $gate->prepareDisposition($result, 'Consumed'));
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $batch = $store->load($run, 1, 'tools');
        $this->assertSame([], $batch->pendingQueue);
        $this->assertSame(['b' => true], $batch->inFlight);
        $this->assertSame(['Consumed', 'Armed'], array_column(array_values($batch->executionAuthorizations), 'state'));
        $this->assertIsString($gate->claim($second));
        $this->assertNull($gate->claim($second));
    }

    public function testSuspensionAndAnswerPreparationDoNotChangeDurableInvocation(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('prepared human continuation');
        $collector = $container->get(ToolBatchCollector::class);
        $store = $container->get(ToolBatchStoreInterface::class);
        $transitions = $container->get(PreparedTransitionEventStoreInterface::class);
        $call = $this->call($run, 'a', 0);
        $collector->registerExpectedBatch($run, 1, 'tools', [$call]);
        $gate = $container->get(ToolExecutionAuthorization::class);
        $gate->arm($call);
        $claim = $gate->claim($call);
        $this->assertIsString($claim);
        $ref = ['run_id' => $run, 'turn_no' => 1, 'step_id' => 'tools', 'tool_call_id' => 'a'];
        $request = PendingHumanInputRequestDTO::toolCallFromPayload(['question_id' => 'q', 'question' => 'Proceed?', 'options' => ['yes', 'no']], $ref);
        $suspension = new ToolCallResult($run, 1, 'tools', 1, 'invocation-a', 'a', 0, pendingHumanInput: $request);
        $gate->saveResult($call, $claim, $suspension);
        $before = $store->load($run, 1, 'tools');
        $handler = $container->get(ToolCallResultHandler::class);
        $decision = $handler->handle($suspension, new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, activeStepId: 'tools', pendingToolCalls: ['a' => false]));
        $this->assertEquals($before, $store->load($run, 1, 'tools'));
        $this->stage($run, $decision, $gate->prepareDisposition($suspension, 'Consumed'));
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $suspended = $store->load($run, 1, 'tools');
        $this->assertSame(['a' => 'q'], $suspended->awaitingHumanInput);
        $answer = new ToolCallHumanInputAnswerDTO('q', 'yes', $ref, $request->payload);
        $prepared = $collector->prepareHumanInputAnswer($run, 1, 'tools', 'a', 'q', $answer);
        $this->assertEquals($suspended, $store->load($run, 1, 'tools'));
        $this->assertCount(1, $prepared->effects);
        $this->assertNotNull($prepared->action);
        $transitions->appendTransition([], ['run_id' => $run, 'predecessor_seq' => $transitions->latestSequenceFor($run) ?? 0, 'actions' => [$prepared->action], 'post_commit_effects' => $prepared->effects]);
        $container->get(ToolBatchCoordinationHandler::class)($prepared->action);
        // Worker receipts are outside the prepared delta and remain unchanged.
        $this->assertEquals($suspended->executionAuthorizations, $store->load($run, 1, 'tools')->executionAuthorizations);
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $resumed = $store->load($run, 1, 'tools');
        $this->assertTrue($resumed->calls['a']->humanInputAnswer->isEquivalent($answer));
        $this->assertSame([], $resumed->awaitingHumanInput);
        $this->assertSame(['a' => true], $resumed->inFlight);
        $this->assertEquals($prepared->effects, $collector->prepareHumanInputRedrive($run, 1, 'tools', 'q', 'yes')->effects);
        $this->assertEquals($resumed, $store->load($run, 1, 'tools'));
        $this->assertNull($gate->claim($call));

        // A later hook can suspend the revised invocation with a new question.
        $revised = $prepared->effects[0];
        $revisedClaim = $gate->claim($revised);
        $this->assertIsString($revisedClaim);
        $secondRequest = PendingHumanInputRequestDTO::toolCallFromPayload(['question_id' => 'q2', 'question' => 'Continue?', 'options' => ['yes', 'no']], $ref);
        $secondSuspension = new ToolCallResult($run, 1, 'tools', 1, 'invocation-a', 'a', 0, pendingHumanInput: $secondRequest);
        $gate->saveResult($revised, $revisedClaim, $secondSuspension);
        $secondDecision = $handler->handle($secondSuspension, $decision->nextState->with(['status' => RunStatus::Running, 'pendingHumanInputRequests' => []]));
        $this->stage($run, $secondDecision, $gate->prepareDisposition($secondSuspension, 'Consumed'));
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $newQuestion = $store->load($run, 1, 'tools');
        $this->assertSame(['a' => 'q2'], $newQuestion->awaitingHumanInput);
        $this->assertNull($newQuestion->calls['a']->humanInputAnswer);
        try {
            $collector->prepareHumanInputAnswer($run, 1, 'tools', 'a', 'q', $answer);
            $this->fail('An old answer must not replace the new pending question.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('question_id mismatch', $exception->getMessage());
        }
        $this->assertEquals($newQuestion, $store->load($run, 1, 'tools'));
        $this->assertTrue($gate->isDisposed($suspension));
        $this->assertTrue($gate->isDisposed($secondSuspension));
        $this->assertNull($gate->claim($revised));
    }

    private function call(string $run, string $id, int $order): ExecuteToolCall
    {
        return new ExecuteToolCall($run, 1, 'tools', 1, 'invocation-'.$id, $id, 'read', ['path' => $id], $order, mode: 'parallel', maxParallelism: 2);
    }

    private function stage(string $run, HandlerResult $decision, ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $disposition): void
    {
        $this->assertNotNull($decision->nextState);
        $this->assertNotNull($disposition);
        $transitions = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $transitions->appendTransition($decision->events, ['run_id' => $run, 'predecessor_seq' => $transitions->latestSequenceFor($run) ?? 0, 'actions' => $decision->postCommitActions, 'post_commit_effects' => $decision->postCommitEffects, 'result_disposition' => $disposition]);
    }
}
