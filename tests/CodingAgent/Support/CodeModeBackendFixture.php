<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Container\ContainerInterface;

final readonly class CodeModeBackendFixture
{
    public RunState $state;
    public ExecuteToolCall $call;

    public function __construct(private ContainerInterface $container, string $script)
    {
        $run = $container->get(HatfieldSessionStore::class)->createSession();
        $this->call = new ExecuteToolCall($run, 1, 'step', 1, 'original-execution', 'cm', 'code_mode', ['script' => $script], 0, mode: 'sequential', timeoutSeconds: 8);
        $assistant = new AgentMessage('assistant', [], metadata: ['tool_calls' => [['id' => 'cm', 'name' => 'code_mode', 'arguments' => ['script' => $script], 'order_index' => 0]]]);
        $this->state = RunStateBuilder::running($run)->withTurnNo(1)->withLastSeq(3)->withActiveStepId('step')->withPendingToolCalls(['cm' => false])->withMessages([$assistant])->build();
        $events = (new EventFactory())->eventsFromSpecs($run, 1, 1, [
            ['type' => 'run_started', 'payload' => ['step_id' => 'step', 'payload' => ['metadata' => ['model' => 'model', 'reasoning' => 'medium']]]],
            ['type' => 'turn_advanced', 'payload' => ['turn_no' => 1, 'step_id' => 'step']],
            ['type' => 'llm_step_completed', 'payload' => ['assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => $assistant->metadata['tool_calls']]]],
        ]);
        $container->get(PreparedTransitionEventStoreInterface::class)->appendTransition($events, ['run_id' => $run, 'predecessor_seq' => 0]);
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $container->get(RunOperationalProjectionRepository::class)->replace($this->state);
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $run, 1, 'step', [$this->call]);
    }

    public function commit(RunState $state, HandlerResult $result): RunState
    {
        $this->container->get(PreparedTransitionEventStoreInterface::class)->appendTransition($result->events, ['run_id' => $state->runId, 'predecessor_seq' => $state->lastSeq, 'actions' => $result->postCommitActions]);
        $this->container->get(PendingTransitionRecovery::class)->recover($state->runId);
        $next = $result->nextState ?? $state;
        $this->container->get(RunOperationalProjectionRepository::class)->replace($next);

        return $next;
    }
}
