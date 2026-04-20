<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Application\Handler\ToolCatalogResolver;
use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;

/**
 * The Platform class orchestrates the execution of AI agent runs within the Symfony infrastructure, bridging high-level agent inputs with the underlying provider invoker. It manages context resolution, message transformation, and hook application to prepare requests for external AI providers. The class ensures proper lifecycle management of runs and cancellation tokens while abstracting provider-specific details.
 */
final readonly class Platform implements PlatformInterface
{
    /**
     * Initializes the platform with required infrastructure collaborators and optional context hooks.
     *
     * @param iterable<TransformContextHookInterface>      $transformContextHooks
     * @param iterable<ConvertToLlmHookInterface>          $convertToLlmHooks
     * @param iterable<BeforeProviderRequestHookInterface> $beforeProviderRequestHooks
     */
    public function __construct(
        private SymfonyPlatformInvoker $invoker,
        private RunStoreInterface $runStore,
        private ToolCatalogResolver $toolCatalogResolver,
        private SymfonyMessageMapper $messageMapper,
        private iterable $transformContextHooks = [],
        private iterable $convertToLlmHooks = [],
        private iterable $beforeProviderRequestHooks = [],
        private ?ModelResolverInterface $modelResolver = null,
        private string $defaultModel = 'gpt-4o-mini',
    ) {
    }

    public function invoke(string $model, array $input, array $options = []): array
    {
        $runContext = $this->runContextFrom($input);
        $cancelToken = $this->cancellationToken($runContext['run_id'] ?? null, $options['cancel_token'] ?? null);

        $messages = $this->resolveContextMessages($input, $runContext['run_id'] ?? null);
        $messages = $this->applyTransformHooks($messages, $cancelToken);

        $llmMessages = $this->applyConvertHooks($messages, $cancelToken);

        $providerModel = 'default' === $model ? $this->defaultModel : $model;
        $providerOptions = $this->optionsWithoutInternalFields($options);

        if (null !== $this->modelResolver) {
            $resolvedModel = $this->modelResolver->resolve($providerModel, $llmMessages, $runContext, $providerOptions);
            $providerModel = $resolvedModel->model;
            $providerOptions = array_replace($providerOptions, $resolvedModel->options);
        }

        $tools = $this->toolCatalogResolver->resolveProviderPayload($runContext);
        if ([] !== $tools && !\array_key_exists('tools', $providerOptions)) {
            $providerOptions['tools'] = $tools;
        }

        $requestInput = ['messages' => $llmMessages->all()];

        [$providerModel, $requestInput, $providerOptions] = $this->applyBeforeProviderRequestHooks(
            model: $providerModel,
            input: $requestInput,
            options: $providerOptions,
            cancelToken: $cancelToken,
        );

        $providerInput = $this->providerInputFrom($requestInput);

        $result = $this->invoker->invoke(
            model: $providerModel,
            input: $providerInput,
            toolDefinitions: $tools,
            options: $providerOptions,
            cancelToken: $cancelToken,
        );

        return $result->toArray();
    }

    /**
     * Constructs the run context array from the provided input payload.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function runContextFrom(array $input): array
    {
        $runId = \is_string($input['run_id'] ?? null) ? $input['run_id'] : null;

        if (null === $runId && \is_string($input['context_ref'] ?? null)) {
            if (1 === preg_match('/^hot:run:(?<run_id>.+)$/', $input['context_ref'], $matches)) {
                $runId = $matches['run_id'];
            }
        }

        return array_filter([
            'run_id' => $runId,
            'turn_no' => \is_int($input['turn_no'] ?? null) ? $input['turn_no'] : null,
            'step_id' => \is_string($input['step_id'] ?? null) ? $input['step_id'] : null,
            'context_ref' => \is_string($input['context_ref'] ?? null) ? $input['context_ref'] : null,
            'tools_ref' => \is_string($input['tools_ref'] ?? null) ? $input['tools_ref'] : null,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * Resolves and formats context messages for the agent run using the input and optional run ID.
     *
     * @param array<string, mixed> $input
     *
     * @return list<AgentMessage>
     */
    private function resolveContextMessages(array $input, ?string $runId): array
    {
        if (\is_array($input['messages'] ?? null)) {
            $messages = [];

            foreach ($input['messages'] as $message) {
                if ($message instanceof AgentMessage) {
                    $messages[] = $message;

                    continue;
                }

                if (!\is_array($message)) {
                    continue;
                }

                $hydrated = $this->hydrateMessage($message);
                if (null !== $hydrated) {
                    $messages[] = $hydrated;
                }
            }

            return $messages;
        }

        if (null === $runId) {
            return [];
        }

        $state = $this->runStore->get($runId);
        if (null === $state) {
            return [];
        }

        return $state->messages;
    }

    /**
     * Applies registered transform hooks to messages with cancellation support.
     *
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function applyTransformHooks(array $messages, CancellationTokenInterface $cancelToken): array
    {
        $transformed = $messages;

        foreach ($this->transformContextHooks as $hook) {
            $transformed = $hook->transformContext($transformed, $cancelToken);
        }

        return $transformed;
    }

    /**
     * Applies conversion hooks to messages and returns a MessageBag.
     *
     * @param list<AgentMessage> $messages
     */
    private function applyConvertHooks(array $messages, CancellationTokenInterface $cancelToken): MessageBag
    {
        $resolvedMessageBag = null;

        foreach ($this->convertToLlmHooks as $hook) {
            $resolvedMessageBag = $hook->convertToLlm($messages, $cancelToken);
        }

        return $resolvedMessageBag ?? $this->messageMapper->toMessageBag($messages);
    }

    /**
     * Executes pre-request hooks on model, input, and options with cancellation support.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function applyBeforeProviderRequestHooks(
        string $model,
        array $input,
        array $options,
        CancellationTokenInterface $cancelToken,
    ): array {
        $resolvedModel = $model;
        $resolvedInput = $input;
        $resolvedOptions = $options;

        foreach ($this->beforeProviderRequestHooks as $hook) {
            $request = $hook->beforeProviderRequest($resolvedModel, $resolvedInput, $resolvedOptions, $cancelToken);
            if (null === $request) {
                continue;
            }

            $resolved = $request->applyOn($resolvedModel, $resolvedInput, $resolvedOptions);
            $resolvedModel = $resolved['model'];
            $resolvedInput = $resolved['input'];
            $resolvedOptions = $resolved['options'];
        }

        return [$resolvedModel, $resolvedInput, $resolvedOptions];
    }

    /**
     * Transforms the agent input into the format expected by the AI provider.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>|object
     */
    private function providerInputFrom(array $input): array|object
    {
        if (\array_key_exists('provider_input', $input) && (\is_array($input['provider_input']) || \is_object($input['provider_input']))) {
            return $input['provider_input'];
        }

        $messages = \is_array($input['messages'] ?? null) ? $input['messages'] : null;
        if (null === $messages) {
            return $input;
        }

        $normalized = [];
        foreach ($messages as $message) {
            if ($message instanceof AgentMessage || \is_object($message)) {
                $normalized[] = $message;
            }
        }

        return $this->messageMapper->toProviderInput($this->messageMapper->toMessageBag($normalized));
    }

    /**
     * Filters out internal fields from options before passing to the provider.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function optionsWithoutInternalFields(array $options): array
    {
        unset($options['cancel_token']);

        return $options;
    }

    private function cancellationToken(?string $runId, mixed $provided): CancellationTokenInterface
    {
        if ($provided instanceof CancellationTokenInterface) {
            return $provided;
        }

        if (null !== $runId) {
            return new RunCancellationToken($this->runStore, $runId);
        }

        return new NullCancellationToken();
    }

    /**
     * Converts a message payload array into an AgentMessage object.
     *
     * @param array<string, mixed> $payload
     */
    private function hydrateMessage(array $payload): ?AgentMessage
    {
        $role = $payload['role'] ?? null;
        $rawContent = $payload['content'] ?? null;

        if (!\is_string($role) || !\is_array($rawContent)) {
            return null;
        }

        $content = [];
        foreach ($rawContent as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $content[] = $contentPart;
        }

        return new AgentMessage(
            role: $role,
            content: $content,
            timestamp: null,
            name: \is_string($payload['name'] ?? null) ? $payload['name'] : null,
            toolCallId: \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null,
            toolName: \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null,
            details: $payload['details'] ?? null,
            isError: \is_bool($payload['is_error'] ?? null) ? $payload['is_error'] : false,
            metadata: \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }
}
