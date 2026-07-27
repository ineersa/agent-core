<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;

/**
 * Thesis: non-content context-budget marker keys are persisted on
 * LlmStepCompleted payload; reminder prose is not.
 */
final class LlmStepResultHandlerContextBudgetMarkersTest extends TestCase
{
    public function testCompletedPayloadIncludesHandledMarkerKeysOnly(): void
    {
        $handler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter(new CommandHandlerRegistry([])),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
        );

        $state = new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 1,
            activeStepId: 'step-1',
            model: 'test/model',
        );

        $result = $handler->handle(new LlmStepResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'k1',
            assistantMessage: new AssistantMessage(new Text('done')),
            usage: ['input_tokens' => 210000],
            stopReason: 'end_turn',
            contextBudgetReminderHandledKeys: ['early'],
        ), $state);

        $completed = null;
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $completed = $event;
                break;
            }
        }

        $this->assertNotNull($completed);
        $this->assertSame(['early'], $completed->payload['context_budget_reminders_handled'] ?? null);
        $encoded = json_encode($completed->payload);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Context usage is already very high', $encoded);
        $this->assertStringNotContainsString('nearly exhausted', $encoded);
    }
}
