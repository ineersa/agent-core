<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ForkExecutionServiceInterface;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Contract: fork tool must surface deferred completion like subagent, not a blocking string result.
 */
#[CoversClass(ForkToolHandler::class)]
final class ForkToolHandlerTest extends TestCase
{
    public function testInvokeReturnsDeferredOutcomeAndDelegatesLaunchParameters(): void
    {
        $parentRunId = 'parent-fork-contract-1';
        $toolCallId = 'call-fork-contract-1';
        $expectedOutcome = new DeferredToolCompletionOutcome('deferred-fork-lifecycle-1');
        $fake = new FakeForkExecutionService($expectedOutcome);

        $accessor = new StackToolExecutionContextAccessor();
        $handler = new ForkToolHandler(
            $accessor,
            new ToolRuntime($accessor),
            new NarrowExecutionServiceLocator($fake),
        );

        $context = new ToolContext(
            runId: $parentRunId,
            turnNo: 2,
            toolCallId: $toolCallId,
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        $outcome = $accessor->with($context, static function () use ($handler): DeferredToolCompletionOutcome {
            /** @var DeferredToolCompletionOutcome */
            return $handler->__invoke([
                'task' => '  Investigate sequence allocator  ',
                'model' => 'test/model',
                'thinking' => 'high',
            ]);
        });

        $this->assertSame($expectedOutcome, $outcome);
        $this->assertSame($parentRunId, $fake->lastParentRunId);
        $this->assertSame('Investigate sequence allocator', $fake->lastTask);
        $this->assertSame('test/model', $fake->lastModelOverride);
        $this->assertSame('high', $fake->lastReasoningOverride);
    }
}

final class FakeForkExecutionService implements ForkExecutionServiceInterface
{
    public ?string $lastParentRunId = null;

    public ?string $lastTask = null;

    public ?string $lastModelOverride = null;

    public ?string $lastReasoningOverride = null;

    public function __construct(
        private readonly DeferredToolCompletionOutcome $outcome,
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome {
        $this->lastParentRunId = $parentRunId;
        $this->lastTask = $task;
        $this->lastModelOverride = $modelOverride;
        $this->lastReasoningOverride = $reasoningOverride;

        return $this->outcome;
    }
}

final class NarrowExecutionServiceLocator implements ContainerInterface
{
    public function __construct(
        private readonly FakeForkExecutionService $execution,
    ) {
    }

    public function get(string $id): FakeForkExecutionService
    {
        if ('execution' !== $id) {
            throw new \LogicException(\sprintf('Unexpected locator key: %s', $id));
        }

        return $this->execution;
    }

    public function has(string $id): bool
    {
        return 'execution' === $id;
    }
}
