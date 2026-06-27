<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ToolExecutorTest extends TestCase
{
    public function testInterruptModeProducesInterruptPayload(): void
    {
        $executor = new ToolExecutor('interrupt', 30, 2, new ToolExecutionResultStore());

        $result = $executor->execute(ToolCallBuilder::create('tool-call-1')
            ->withToolName('ask_user')
            ->withArguments([
                'question_id' => 'q-1',
                'prompt' => 'Approve deployment?',
                'schema' => ['type' => 'boolean'],
            ])
            ->withOrderIndex(0)
            ->withRunId('run-stage-06')
            ->withMode(ToolExecutionMode::Interrupt)
            ->build());

        $this->assertFalse($result->isError);
        $this->assertIsArray($result->details);
        $this->assertSame('interrupt', $result->details['kind']);
        $this->assertSame('q-1', $result->details['question_id']);
        $this->assertSame('Approve deployment?', $result->details['prompt']);
    }

    public function testToolExecutionIsUnavailableWithoutToolbox(): void
    {
        $executor = new ToolExecutor('parallel', 30, 2, new ToolExecutionResultStore());

        $result = $executor->execute(ToolCallBuilder::create('call-1')
            ->withToolName('web_search')
            ->withArguments(['query' => 'symfony'])
            ->withOrderIndex(0)
            ->build());

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('execution is unavailable', $result->content[0]['text']);
    }

    public function testRunScopedDedupeReusesTerminalToolResult(): void
    {
        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $first = $executor->execute(ToolCallBuilder::create('call-1')
            ->withToolName('web_search')
            ->withArguments(['query' => 'symfony'])
            ->withOrderIndex(0)
            ->withRunId('run-stage-06')
            ->build());

        $second = $executor->execute(ToolCallBuilder::create('call-1')
            ->withToolName('web_search')
            ->withArguments(['query' => 'symfony'])
            ->withOrderIndex(0)
            ->withRunId('run-stage-06')
            ->build());

        $this->assertSame(1, $toolbox->executions);
        $this->assertFalse($first->isError);
        $this->assertFalse($second->isError);
        $this->assertSame('run_tool_call_dedupe', $second->details['idempotency_reuse_reason']);
    }

    public function testToolIdempotencyKeyReusePreventsDuplicateExternalExecution(): void
    {
        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $executor->execute(ToolCallBuilder::create('call-1')
            ->withToolName('web_search')
            ->withArguments(['query' => 'symfony'])
            ->withOrderIndex(0)
            ->withRunId('run-stage-06')
            ->withToolIdempotencyKey('idem-1')
            ->build());

        $second = $executor->execute(ToolCallBuilder::create('call-2')
            ->withToolName('web_search')
            ->withArguments(['query' => 'symfony'])
            ->withOrderIndex(1)
            ->withRunId('run-stage-06')
            ->withToolIdempotencyKey('idem-1')
            ->build());

        $this->assertSame(1, $toolbox->executions);
        $this->assertSame('call-2', $second->toolCallId);
        $this->assertSame('tool_idempotency_reuse', $second->details['idempotency_reuse_reason']);
    }

    public function testSymfonyToolboxRequestedEventCanDenyExecution(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event): void {
            $event->deny('Blocked by policy listener.');
        });

        $toolbox = new Toolbox([new SymfonySearchTool()], eventDispatcher: $dispatcher);

        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(ToolCallBuilder::create('call-3')
            ->withToolName('web_search')
            ->withArguments(['query' => 'agent core'])
            ->withOrderIndex(0)
            ->build());

        $this->assertFalse($result->isError);
        $this->assertSame('Blocked by policy listener.', $result->details['raw_result']);
        $this->assertSame('Blocked by policy listener.', $result->content[0]['text']);
    }

    public function testContextAccessorWrapsToolboxExecution(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
            contextAccessor: $accessor,
        );

        $executor->execute(ToolCallBuilder::create('call-1')
            ->withToolName('web_search')
            ->withArguments(['query' => 'symfony'])
            ->withOrderIndex(0)
            ->withRunId('run-1')
            ->withContext(['turn_no' => 1, 'cancel_token' => new class implements CancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return false;
                }
            }])
            ->build());

        // Context should be available during execution (CountingToolbox checks this).
        $this->assertNull($accessor->current());
    }

    /* ───────── Execution allowlist enforcement ───────── */

    public function testAllowlistDeniesToolNotInList(): void
    {
        $denyResolver = new class implements ToolSetResolverInterface {
            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: ['allowed_tool'],
                    allowListNames: ['allowed_tool'],
                );
            }
        };

        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
            toolSetResolver: $denyResolver,
        );

        $result = $executor->execute(ToolCallBuilder::create('call-deny')
            ->withToolName('evil_tool')
            ->withArguments([])
            ->withOrderIndex(0)
            ->withContext(['tools_ref' => 'toolset:run:r1:turn:1'])
            ->build());

        $this->assertTrue($result->isError);
        $this->assertSame(0, $toolbox->executions, 'Toolbox should not be called for denied tool.');
        $this->assertSame('evil_tool', $result->toolName);
        $this->assertIsArray($result->details);
        $this->assertTrue($result->details['denied']);
        $this->assertSame('not_in_active_allowlist', $result->details['reason']);
        $this->assertSame(['allowed_tool'], $result->details['available_tools']);
        $this->assertStringContainsString('is not in the active execution allowlist', $result->content[0]['text']);
    }

    public function testAllowlistAllowsToolInList(): void
    {
        $allowResolver = new class implements ToolSetResolverInterface {
            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: ['allowed_tool'],
                    allowListNames: ['allowed_tool'],
                );
            }
        };

        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
            toolSetResolver: $allowResolver,
        );

        $result = $executor->execute(ToolCallBuilder::create('call-allow')
            ->withToolName('allowed_tool')
            ->withArguments([])
            ->withOrderIndex(0)
            ->withContext(['tools_ref' => 'toolset:run:r1:turn:1'])
            ->build());

        $this->assertFalse($result->isError);
        $this->assertSame(1, $toolbox->executions, 'Toolbox should be called for allowed tool.');
    }

    public function testAllowlistSkippedWhenNoToolsRef(): void
    {
        $spyResolver = new class implements ToolSetResolverInterface {
            public bool $resolved = false;

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                $this->resolved = true;

                return new ActiveToolSet(
                    toolNames: ['allowed_tool'],
                    allowListNames: ['allowed_tool'],
                );
            }
        };

        $toolbox = new CountingToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
            toolSetResolver: $spyResolver,
        );

        $result = $executor->execute(ToolCallBuilder::create('call-no-ref')
            ->withToolName('any_tool')
            ->withArguments([])
            ->withOrderIndex(0)
            // No context['tools_ref'] — should skip allowlist check
            ->build());

        $this->assertFalse($spyResolver->resolved, 'Resolver should not be called when no tools_ref in context.');
        $this->assertFalse($result->isError);
        $this->assertSame(1, $toolbox->executions);
    }

    public function testAllowlistSkippedWhenNoResolver(): void
    {
        $toolbox = new CountingToolbox();
        // No toolSetResolver passed.
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(ToolCallBuilder::create('call-no-resolver')
            ->withToolName('some_tool')
            ->withArguments([])
            ->withOrderIndex(0)
            ->withContext(['tools_ref' => 'toolset:run:r1:turn:1'])
            ->build());

        $this->assertFalse($result->isError);
        $this->assertSame(1, $toolbox->executions);
    }

    /* ───────── Context accessor ───────── */

    /* ───────── ToolCallException handling ───────── */

    public function testToolCallExceptionWithRetryableAndHintProducesStructuredDetails(): void
    {
        $toolbox = new ThrowingToolCallExceptionToolbox(false, 'Something went wrong', 'Try again with different input');
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(ToolCallBuilder::create('call-tce-1')
            ->withToolName('some_tool')
            ->withArguments(['x' => 1])
            ->withOrderIndex(0)
            ->build());

        $this->assertTrue($result->isError);
        $this->assertIsArray($result->details);
        $this->assertSame('Try again with different input', $result->details['hint']);
        $this->assertFalse($result->details['retryable']);
        $this->assertStringContainsString('Something went wrong', $result->content[0]['text']);
        $this->assertStringContainsString('Try again with different input', $result->content[0]['text']);
    }

    public function testToolCallExceptionWithRetryableTrueProducesRetryableDetails(): void
    {
        $toolbox = new ThrowingToolCallExceptionToolbox(true, 'Temporary failure');
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(ToolCallBuilder::create('call-tce-2')
            ->withToolName('some_tool')
            ->withArguments(['x' => 1])
            ->withOrderIndex(0)
            ->build());

        $this->assertTrue($result->isError);
        $this->assertTrue($result->details['retryable']);
        $this->assertNull($result->details['hint']);
        $this->assertStringContainsString('Temporary failure', $result->content[0]['text']);
    }

    public function testRegularRuntimeExceptionStillProducesPlainErrorResult(): void
    {
        $toolbox = new ThrowingToolCallExceptionToolbox(false, 'Boom!', null, false);
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(ToolCallBuilder::create('call-re-1')
            ->withToolName('some_tool')
            ->withArguments(['x' => 1])
            ->withOrderIndex(0)
            ->build());

        $this->assertTrue($result->isError);
        $this->assertIsArray($result->details);
        $this->assertArrayHasKey('error_type', $result->details);
        $this->assertSame(\RuntimeException::class, $result->details['error_type']);
        $this->assertArrayNotHasKey('retryable', $result->details);
        $this->assertArrayNotHasKey('hint', $result->details);
        $this->assertStringContainsString('Boom!', $result->content[0]['text']);
    }

    public function testContextAccessorSetsCorrectValues(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $toolbox = new ContextCheckingToolbox($accessor);
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 60,
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
            contextAccessor: $accessor,
        );

        $result = $executor->execute(ToolCallBuilder::create('call-42')
            ->withToolName('read')
            ->withArguments(['path' => 'file.txt'])
            ->withOrderIndex(1)
            ->withRunId('run-context-test')
            ->withTimeoutSeconds(120)
            ->withContext(['turn_no' => 3, 'cancel_token' => new class implements CancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return false;
                }
            }])
            ->build());

        $this->assertFalse($result->isError);
        $this->assertNull($accessor->current());
    }
    public function testNoPostHocTimeoutWhenPolicyTimeoutIsNull(): void
    {
        $toolbox = new SlowToolbox();
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: null,
            maxParallelism: 2,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
        );

        $result = $executor->execute(ToolCallBuilder::create('call-slow')
            ->withToolName('slow_tool')
            ->withArguments([])
            ->withOrderIndex(0)
            ->withTimeoutSeconds(null)
            ->build());

        $this->assertFalse($result->isError);
        $this->assertSame('slow-ok', $result->content[0]['text']);
        $this->assertNull($result->details['timeout_seconds'] ?? null);
    }

}


    final class ContextCheckingToolbox implements ToolboxInterface
{
    public function __construct(
        private readonly StackToolExecutionContextAccessor $accessor,
    ) {
    }

    public function getTools(): array
    {
        return [];
    }

    public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
    {
        $context = $this->accessor->requireCurrent();

        \assert('call-42' === $context->toolCallId(), \sprintf('Expected call-42, got %s', $context->toolCallId()));
        \assert('read' === $context->toolName(), \sprintf('Expected read, got %s', $context->toolName()));
        \assert('run-context-test' === $context->runId(), \sprintf('Expected run-context-test, got %s', $context->runId()));
        \assert(3 === $context->turnNo(), \sprintf('Expected 3, got %d', $context->turnNo()));
        \assert(120 === $context->timeoutSeconds(), \sprintf('Expected 120, got %d', $context->timeoutSeconds()));
        \assert(false === $context->cancellationToken()->isCancellationRequested());

        return new SymfonyToolResult($toolCall, ['status' => 'ok']);
    }
}

final class ThrowingToolCallExceptionToolbox implements ToolboxInterface
{
    public function __construct(
        private readonly bool $retryable,
        private readonly string $message,
        private readonly ?string $hint = null,
        private readonly bool $useToolCallException = true,
    ) {
    }

    public function getTools(): array
    {
        return [];
    }

    public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
    {
        if ($this->useToolCallException) {
            throw new ToolCallException($this->message, retryable: $this->retryable, hint: $this->hint);
        }

        throw new \RuntimeException($this->message);
    }
}

final class CountingToolbox implements ToolboxInterface
{
    public int $executions = 0;

    public function getTools(): array
    {
        return [];
    }

    public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
    {
        ++$this->executions;

        return new SymfonyToolResult($toolCall, ['status' => 'ok']);
    }
}

#[AsTool(name: 'web_search', description: 'Searches the web for relevant snippets.')]
final class SymfonySearchTool
{
    /**
     * @return array{query: string, status: string}
     */
    public function __invoke(string $query): array
    {
        return [
            'query' => $query,
            'status' => 'ok',
        ];
    }
}


final class SlowToolbox implements ToolboxInterface
{
    public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
    {
        usleep(50_000);

        return new SymfonyToolResult($toolCall, 'slow-ok');
    }

    public function getTools(): array
    {
        return [];
    }
}
