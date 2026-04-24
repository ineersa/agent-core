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
use Ineersa\AgentCore\Domain\Tool\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionContext;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Tool\PlatformInvocationResult;

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

    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
    {
        $runContext = $this->runContextFrom($request->input);
        $cancelToken = $this->cancellationToken($runContext->runId, $request->options['cancel_token'] ?? null);

        $messages = $this->resolveContextMessages($request->input, $runContext->runId);
        $messages = $this->applyTransformHooks($messages, $cancelToken);

        $llmMessages = $this->applyConvertHooks($messages, $cancelToken);

        $providerModel = 'default' === $request->model ? $this->defaultModel : $request->model;
        $providerOptions = $this->optionsWithoutInternalFields(new ModelResolutionOptions($request->options));

        if (null !== $this->modelResolver) {
            $resolvedModel = $this->modelResolver->resolve(
                $providerModel,
                $llmMessages,
                $runContext,
                new ModelResolutionOptions($providerOptions),
            );
            $providerModel = $resolvedModel->model;
            $providerOptions = array_replace($providerOptions, $resolvedModel->options);
        }

        $tools = $this->toolCatalogResolver->resolveProviderPayload($runContext->asToolCatalogContext());
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

        return $this->invoker->invoke(
            model: $providerModel,
            input: $providerInput,
            toolDefinitions: $tools,
            options: $providerOptions,
            cancelToken: $cancelToken,
        );
    }

    /**
     * Constructs typed model resolution context from invocation input payload.
     *
     * @param array<string, mixed> $input
     */
    private function runContextFrom(array $input): ModelResolutionContext
    {
        $runId = \is_string($input['run_id'] ?? null) ? $input['run_id'] : null;

        if (null === $runId && \is_string($input['context_ref'] ?? null)) {
            if (1 === preg_match('/^hot:run:(?<run_id>.+)$/', $input['context_ref'], $matches)) {
                $runId = $matches['run_id'];
            }
        }

        return new ModelResolutionContext(
            runId: $runId,
            turnNo: \is_int($input['turn_no'] ?? null) ? $input['turn_no'] : null,
            stepId: \is_string($input['step_id'] ?? null) ? $input['step_id'] : null,
            contextRef: \is_string($input['context_ref'] ?? null) ? $input['context_ref'] : null,
            toolsRef: \is_string($input['tools_ref'] ?? null) ? $input['tools_ref'] : null,
        );
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

                $hydrated = AgentMessage::fromPayload($message);
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
     * @return array<string, mixed>
     */
    private function optionsWithoutInternalFields(ModelResolutionOptions $options): array
    {
        return $options->withoutKeys(['cancel_token'])->values;
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
}
