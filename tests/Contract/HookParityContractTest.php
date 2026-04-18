<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Contract;

use Ineersa\AgentCore\Contract\Hook\AfterToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallResult;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ProviderRequest;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

final class HookParityContractTest extends TestCase
{
    public function testProviderBoundaryHooksHaveDocumentedCallOrder(): void
    {
        $recorder = new HookCallRecorder();

        $transform = new class($recorder) implements TransformContextHookInterface {
            public function __construct(private readonly HookCallRecorder $recorder)
            {
            }

            public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
            {
                $this->recorder->record('transform_context');

                return $messages;
            }
        };

        $convert = new class($recorder) implements ConvertToLlmHookInterface {
            public function __construct(private readonly HookCallRecorder $recorder)
            {
            }

            public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null): MessageBag
            {
                $this->recorder->record('convert_to_llm');

                return new MessageBag([new \stdClass()]);
            }
        };

        $beforeProvider = new class($recorder) implements BeforeProviderRequestHookInterface {
            public function __construct(private readonly HookCallRecorder $recorder)
            {
            }

            public function beforeProviderRequest(
                string $model,
                array $input,
                array $options,
                ?CancellationTokenInterface $cancelToken = null,
            ): ?ProviderRequest {
                $this->recorder->record('before_provider_request');

                return new ProviderRequest(model: $model.'-override', input: $input, options: $options);
            }
        };

        $payload = $this->runProviderHookChain(
            [new AgentMessage('user', [['type' => 'text', 'text' => 'hello']])],
            'model-a',
            ['messages' => []],
            ['temperature' => 0.3],
            $transform,
            $convert,
            $beforeProvider,
        );

        self::assertSame(['transform_context', 'convert_to_llm', 'before_provider_request'], $recorder->calls);
        self::assertSame('model-a-override', $payload['request']['model']);
        self::assertCount(1, $payload['llm_messages']->all());
    }

    public function testToolHooksHaveDocumentedCallOrder(): void
    {
        $recorder = new HookCallRecorder();

        $before = new class($recorder) implements BeforeToolCallHookInterface {
            public function __construct(private readonly HookCallRecorder $recorder)
            {
            }

            public function beforeToolCall(
                BeforeToolCallContext $context,
                ?CancellationTokenInterface $cancelToken = null,
            ): ?BeforeToolCallResult {
                $this->recorder->record('before_tool_call');

                return BeforeToolCallResult::allow();
            }
        };

        $after = new class($recorder) implements AfterToolCallHookInterface {
            public function __construct(private readonly HookCallRecorder $recorder)
            {
            }

            public function afterToolCall(
                AfterToolCallContext $context,
                ?CancellationTokenInterface $cancelToken = null,
            ): ?AfterToolCallResult {
                $this->recorder->record('after_tool_call');

                return AfterToolCallResult::withDetails(['status' => 'ok']);
            }
        };

        $this->runToolHookChain($before, $after);

        self::assertSame(['before_tool_call', 'after_tool_call'], $recorder->calls);
    }

    /**
     * @param list<AgentMessage>     $messages
     * @param array<string, mixed>   $input
     * @param array<string, mixed>   $options
     *
     * @return array{
     *     llm_messages: MessageBag,
     *     request: array{model: string, input: array<string, mixed>, options: array<string, mixed>}
     * }
     */
    private function runProviderHookChain(
        array $messages,
        string $model,
        array $input,
        array $options,
        TransformContextHookInterface $transform,
        ConvertToLlmHookInterface $convert,
        BeforeProviderRequestHookInterface $beforeProvider,
    ): array {
        $cancelToken = new NullCancellationToken();

        $transformedMessages = $transform->transformContext($messages, $cancelToken);
        $llmMessages = $convert->convertToLlm($transformedMessages, $cancelToken);
        $providerRequest = $beforeProvider->beforeProviderRequest($model, $input, $options, $cancelToken);

        return [
            'llm_messages' => $llmMessages,
            'request' => ($providerRequest ?? new ProviderRequest())->applyOn($model, $input, $options),
        ];
    }

    private function runToolHookChain(BeforeToolCallHookInterface $before, AfterToolCallHookInterface $after): void
    {
        $assistantMessage = new AgentMessage('assistant', [['type' => 'text', 'text' => 'working']]);
        $toolCall = new ToolCall('tool-call-1', 'web_search', ['query' => 'symfony'], 0);
        $toolResult = new ToolResult('tool-call-1', 'web_search', [['type' => 'text', 'text' => 'ok']], details: ['status' => 'ok']);
        $cancelToken = new NullCancellationToken();

        $beforeContext = new BeforeToolCallContext(
            assistantMessage: $assistantMessage,
            toolCall: $toolCall,
            args: ['query' => 'symfony'],
            context: ['run_id' => 'run-stage-01'],
        );

        $beforeResult = $before->beforeToolCall($beforeContext, $cancelToken);
        if ($beforeResult?->block ?? false) {
            self::fail('beforeToolCall unexpectedly blocked tool execution in contract test.');
        }

        $afterContext = new AfterToolCallContext(
            assistantMessage: $assistantMessage,
            toolCall: $toolCall,
            args: ['query' => 'symfony'],
            result: $toolResult,
            isError: false,
            context: ['run_id' => 'run-stage-01'],
        );

        $after->afterToolCall($afterContext, $cancelToken);
    }
}

final class HookCallRecorder
{
    /** @var list<string> */
    public array $calls = [];

    public function record(string $callName): void
    {
        $this->calls[] = $callName;
    }
}
