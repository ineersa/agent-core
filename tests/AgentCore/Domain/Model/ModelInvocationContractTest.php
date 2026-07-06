<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Model;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelInvocationContractTest extends TestCase
{
    /* ─── ProviderRequest::applyOn() ─── */

    #[DataProvider('applyOnProvider')]
    public function testApplyOnMergeOverride(
        ?string $model,
        ?array $input,
        ?array $options,
        string $defaultModel,
        array $defaultInput,
        array $defaultOptions,
        string $expectedModel,
        array $expectedInput,
        array $expectedOptions,
    ): void {
        $request = new ProviderRequest(model: $model, input: $input, options: $options);

        $result = $request->applyOn($defaultModel, $defaultInput, $defaultOptions);

        $this->assertSame($expectedModel, $result['model']);
        $this->assertSame($expectedInput, $result['input']);
        $this->assertSame($expectedOptions, $result['options']);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?array, 2: ?array, 3: string, 4: array, 5: array, 6: string, 7: array, 8: array}>
     */
    public static function applyOnProvider(): array
    {
        return [
            'all_null_uses_defaults' => [
                null, null, null,
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
            ],
            'model_only_override' => [
                'override-model', null, null,
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'override-model', ['msg' => 'hello'], ['temperature' => 0.5],
            ],
            'input_only_override' => [
                null, ['msg' => 'overridden'], null,
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'default-model', ['msg' => 'overridden'], ['temperature' => 0.5],
            ],
            'options_only_override' => [
                null, null, ['max_tokens' => 2000],
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'default-model', ['msg' => 'hello'], ['max_tokens' => 2000],
            ],
            'full_override' => [
                'final-model', ['msg' => 'final'], ['stop' => ['!']],
                'default-model', ['msg' => 'hello'], ['temperature' => 0.5],
                'final-model', ['msg' => 'final'], ['stop' => ['!']],
            ],
        ];
    }

    public function testApplyOnReturnsExactKeys(): void
    {
        $request = new ProviderRequest(model: 'm', input: ['i'], options: ['o']);
        $result = $request->applyOn('x', ['y'], ['z']);

        $this->assertSame(['model', 'input', 'options'], array_keys($result));
    }

    /* ─── ModelInvocationInput ─── */

    public function testModelInvocationInputPreservesFields(): void
    {
        $message = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'hello']]);

        $input = new ModelInvocationInput(
            runId: 'run-input',
            turnNo: 2,
            stepId: 'step-1',
            contextRef: 'ctx-abc',
            toolsRef: 'tools-xyz',
            messages: [$message],
        );

        $this->assertSame('run-input', $input->runId);
        $this->assertSame(2, $input->turnNo);
        $this->assertSame('step-1', $input->stepId);
        $this->assertSame('ctx-abc', $input->contextRef);
        $this->assertSame('tools-xyz', $input->toolsRef);
        $this->assertCount(1, $input->messages);
        $this->assertSame('hello', $input->messages[0]->content[0]['text']);
    }

    public function testModelInvocationInputAllNullByDefault(): void
    {
        $input = new ModelInvocationInput();

        $this->assertNull($input->runId);
        $this->assertNull($input->turnNo);
        $this->assertNull($input->stepId);
        $this->assertNull($input->contextRef);
        $this->assertNull($input->toolsRef);
        $this->assertNull($input->messages);
    }

    /* ─── ModelInvocationRequest ─── */

    public function testModelInvocationRequestDefaultOptionsIsModelInvocationOptionsWithNullCancelToken(): void
    {
        $request = new ModelInvocationRequest(
            model: 'gpt-4',
            input: new ModelInvocationInput(),
        );

        $this->assertSame('gpt-4', $request->model);
        $this->assertInstanceOf(ModelInvocationOptions::class, $request->options);
        $this->assertNull($request->options->cancelToken);
    }

    public function testModelInvocationRequestPreservesExplicitOptions(): void
    {
        $cancellationToken = $this->createCancellationToken(cancelled: true);

        $options = new ModelInvocationOptions(cancelToken: $cancellationToken);
        $request = new ModelInvocationRequest(
            model: 'claude-3',
            input: new ModelInvocationInput(),
            options: $options,
        );

        $this->assertSame($cancellationToken, $request->options->cancelToken);
        $this->assertTrue($request->options->cancelToken->isCancellationRequested());
    }

    /* ─── PlatformInvocationResult ─── */

    public function testPlatformInvocationResultPreservesFields(): void
    {
        $result = new PlatformInvocationResult(
            assistantMessage: null,
            deltas: [],
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50],
            stopReason: 'end_turn',
            error: null,
        );

        $this->assertNull($result->assistantMessage);
        $this->assertSame([], $result->deltas());
        $this->assertSame(['prompt_tokens' => 100, 'completion_tokens' => 50], $result->usage);
        $this->assertSame('end_turn', $result->stopReason);
        $this->assertNull($result->error);
    }

    public function testPlatformInvocationResultWithError(): void
    {
        $result = new PlatformInvocationResult(
            assistantMessage: null,
            deltas: [],
            usage: [],
            stopReason: null,
            error: ['code' => 429, 'message' => 'Rate limited'],
        );

        $this->assertSame(['code' => 429, 'message' => 'Rate limited'], $result->error);
        $this->assertNull($result->stopReason);
    }

    /**
     * Create a CancellationTokenInterface with a given cancellation state.
     */
    private function createCancellationToken(bool $cancelled): CancellationTokenInterface
    {
        return new TestCancellationTokenStub($cancelled);
    }
}

/**
 * Small named stub implementing CancellationTokenInterface for use in
 * ModelInvocationContractTest. Defined in the same file to keep the stub close
 * to its sole consumer while avoiding anonymous-class overhead in stack traces.
 */
final readonly class TestCancellationTokenStub implements CancellationTokenInterface
{
    public function __construct(private bool $cancelled)
    {
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancelled;
    }
}
