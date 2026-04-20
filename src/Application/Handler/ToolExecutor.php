<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Hook\AfterToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\BeforeToolCallHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolIdempotencyKeyResolverInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionPolicy;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * The ToolExecutor class orchestrates the execution of tool calls within an agent core, handling policy resolution, argument validation, and result caching. It integrates with the Symfony Toolbox for tool invocation and manages execution metadata, including timeouts, parallelism, and cancellation. The class ensures consistent tool result formatting and applies post-execution hooks to the assistant message context.
 */
final class ToolExecutor implements ToolExecutorInterface
{
    private ToolExecutionPolicyResolver $policyResolver;

    private ToolExecutionResultStore $resultStore;

    /**
     * Initializes executor with default mode, timeout, parallelism, overrides, and optional toolbox.
     *
     * @param array<string, array{mode?: string|null, timeout_seconds?: int|null}> $overrides
     * @param iterable<BeforeToolCallHookInterface>                                $beforeToolCallHooks
     * @param iterable<AfterToolCallHookInterface>                                 $afterToolCallHooks
     */
    public function __construct(
        string $defaultMode,
        int $defaultTimeoutSeconds,
        int $maxParallelism,
        array $overrides = [],
        private readonly ?object $toolbox = null,
        private readonly iterable $beforeToolCallHooks = [],
        private readonly iterable $afterToolCallHooks = [],
        ?ToolExecutionResultStore $resultStore = null,
        private readonly ?ToolIdempotencyKeyResolverInterface $toolIdempotencyKeyResolver = null,
    ) {
        $this->policyResolver = new ToolExecutionPolicyResolver($defaultMode, $defaultTimeoutSeconds, $maxParallelism, $overrides);
        $this->resultStore = $resultStore ?? new ToolExecutionResultStore();
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $policy = $this->resolvePolicy($toolCall);
        $runId = $this->runId($toolCall);
        $assistantMessage = $toolCall->assistantMessage ?? new AgentMessage('assistant', []);
        $cancelToken = $this->cancellationToken($toolCall);
        $toolIdempotencyKey = $toolCall->toolIdempotencyKey ?? $this->toolIdempotencyKeyResolver?->resolveToolIdempotencyKey($toolCall);

        if (null !== $runId) {
            $existingByCall = $this->resultStore->findByRunToolCall($runId, $toolCall->toolCallId);
            if (null !== $existingByCall) {
                return $this->reuseResult(
                    toolCall: $toolCall,
                    existing: $existingByCall,
                    policy: $policy,
                    toolIdempotencyKey: $toolIdempotencyKey,
                    reason: 'run_tool_call_dedupe',
                );
            }
        }

        if (null !== $toolIdempotencyKey && '' !== $toolIdempotencyKey) {
            $existingByToolIdempotency = $this->resultStore->findByToolAndIdempotencyKey($toolCall->toolName, $toolIdempotencyKey);
            if (null !== $existingByToolIdempotency) {
                $reused = $this->reuseResult(
                    toolCall: $toolCall,
                    existing: $existingByToolIdempotency,
                    policy: $policy,
                    toolIdempotencyKey: $toolIdempotencyKey,
                    reason: 'tool_idempotency_reuse',
                );

                if (null !== $runId) {
                    $this->resultStore->remember($runId, $toolCall->toolCallId, $toolCall->toolName, $toolIdempotencyKey, $reused);
                }

                return $reused;
            }
        }

        if ($cancelToken->isCancellationRequested()) {
            return $this->rememberAndReturn(
                toolCall: $toolCall,
                policy: $policy,
                toolIdempotencyKey: $toolIdempotencyKey,
                result: $this->errorResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    message: \sprintf('Tool "%s" execution cancelled before start.', $toolCall->toolName),
                    details: ['cancelled' => true],
                ),
            );
        }

        $validationError = $this->validateArguments($toolCall->arguments, $toolCall->context['arg_schema'] ?? $toolCall->context['schema'] ?? null);
        if (null !== $validationError) {
            $validationResult = $this->errorResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                message: $validationError,
                details: ['validation_error' => true],
            );

            $validationResult = $this->applyAfterHooks(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                result: $validationResult,
                cancelToken: $cancelToken,
                context: $this->contextForHooks($toolCall, $policy, $toolIdempotencyKey),
            );

            return $this->rememberAndReturn(
                toolCall: $toolCall,
                policy: $policy,
                toolIdempotencyKey: $toolIdempotencyKey,
                result: $validationResult,
            );
        }

        $hookContext = $this->contextForHooks($toolCall, $policy, $toolIdempotencyKey);

        $blockedMessage = null;
        foreach ($this->beforeToolCallHooks as $hook) {
            $before = $hook->beforeToolCall(new BeforeToolCallContext(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                args: $toolCall->arguments,
                context: $hookContext,
            ), $cancelToken);

            if (null !== $before && $before->block) {
                $blockedMessage = $before->reason ?? \sprintf('Execution of tool "%s" was blocked by before_tool_call hook.', $toolCall->toolName);
                break;
            }
        }

        $durationMs = null;

        if (null !== $blockedMessage) {
            $result = $this->errorResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                message: $blockedMessage,
                details: ['blocked' => true],
            );
        } else {
            $startedAt = hrtime(true);

            try {
                $result = $this->executeToolCall($toolCall, $policy);
            } catch (\Throwable $exception) {
                $result = $this->errorResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    message: $exception->getMessage(),
                    details: ['error_type' => $exception::class],
                );
            }

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            if ($durationMs > $policy->timeoutSeconds * 1000) {
                $result = $this->errorResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    message: \sprintf('Tool "%s" timed out after %d second(s).', $toolCall->toolName, $policy->timeoutSeconds),
                    details: [
                        'timed_out' => true,
                        'timeout_seconds' => $policy->timeoutSeconds,
                    ],
                );
            }
        }

        $postExecutionCancellationToken = $this->cancellationToken($toolCall);
        if ($postExecutionCancellationToken->isCancellationRequested()) {
            $result = $this->errorResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                message: \sprintf('Tool "%s" result marked stale due to run cancellation.', $toolCall->toolName),
                details: [
                    'stale_due_to_cancel' => true,
                ],
            );
        }

        $result = $this->applyAfterHooks(
            assistantMessage: $assistantMessage,
            toolCall: $toolCall,
            result: $result,
            cancelToken: $cancelToken,
            context: $hookContext,
        );

        return $this->rememberAndReturn(
            toolCall: $toolCall,
            policy: $policy,
            toolIdempotencyKey: $toolIdempotencyKey,
            result: $result,
            durationMs: $durationMs,
        );
    }

    private function executeToolCall(ToolCall $toolCall, ToolExecutionPolicy $policy): ToolResult
    {
        if (ToolExecutionMode::Interrupt === $policy->mode || 'ask_user' === $toolCall->toolName) {
            return $this->interruptResult($toolCall);
        }

        if ($this->canUseSymfonyToolbox()) {
            $symfonyToolCall = $this->toSymfonyToolCall($toolCall);
            $toolboxResult = $this->toolbox->execute($symfonyToolCall);

            $rawResult = method_exists($toolboxResult, 'getResult')
                ? $toolboxResult->getResult()
                : null;

            $details = [
                'raw_result' => $rawResult,
            ];

            if (method_exists($toolboxResult, 'getSources')) {
                $details['sources'] = $toolboxResult->getSources();
            }

            if (\is_array($rawResult) && 'interrupt' === ($rawResult['kind'] ?? null)) {
                $details = array_replace($details, $rawResult);
            }

            return new ToolResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                content: [[
                    'type' => 'text',
                    'text' => $this->stringify($rawResult),
                ]],
                details: $details,
                isError: false,
            );
        }

        return $this->errorResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            message: \sprintf(
                'Tool "%s" execution is unavailable (mode=%s). Configure Symfony Toolbox integration.',
                $toolCall->toolName,
                $policy->mode->value,
            ),
            details: [
                'unavailable' => true,
            ],
        );
    }

    private function resolvePolicy(ToolCall $toolCall): ToolExecutionPolicy
    {
        $resolved = $this->policyResolver->resolve($toolCall->toolName);

        return new ToolExecutionPolicy(
            mode: $toolCall->mode ?? $resolved->mode,
            timeoutSeconds: max(1, $toolCall->timeoutSeconds ?? $resolved->timeoutSeconds),
            maxParallelism: max(1, (int) ($toolCall->context['max_parallelism'] ?? $resolved->maxParallelism)),
        );
    }

    private function rememberAndReturn(
        ToolCall $toolCall,
        ToolExecutionPolicy $policy,
        ?string $toolIdempotencyKey,
        ToolResult $result,
        ?float $durationMs = null,
    ): ToolResult {
        $normalized = $this->withExecutionMetadata($result, $policy, $toolIdempotencyKey, $durationMs);

        $runId = $this->runId($toolCall);
        if (null !== $runId) {
            $this->resultStore->remember($runId, $toolCall->toolCallId, $toolCall->toolName, $toolIdempotencyKey, $normalized);
        }

        return $normalized;
    }

    private function reuseResult(
        ToolCall $toolCall,
        ToolResult $existing,
        ToolExecutionPolicy $policy,
        ?string $toolIdempotencyKey,
        string $reason,
    ): ToolResult {
        $reused = new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: $existing->content,
            details: $existing->details,
            isError: $existing->isError,
        );

        return $this->withExecutionMetadata($reused, $policy, $toolIdempotencyKey, null, $reason);
    }

    private function withExecutionMetadata(
        ToolResult $result,
        ToolExecutionPolicy $policy,
        ?string $toolIdempotencyKey,
        ?float $durationMs,
        ?string $reusedReason = null,
    ): ToolResult {
        $details = \is_array($result->details)
            ? $result->details
            : ['raw_details' => $result->details];

        $details['mode'] = $policy->mode->value;
        $details['timeout_seconds'] = $policy->timeoutSeconds;
        $details['max_parallelism'] = $policy->maxParallelism;

        if (null !== $toolIdempotencyKey && '' !== $toolIdempotencyKey) {
            $details['tool_idempotency_key'] = $toolIdempotencyKey;
        }

        if (null !== $durationMs) {
            $details['duration_ms'] = max(0, (int) round($durationMs));
        }

        if (null !== $reusedReason) {
            $details['idempotency_reused'] = true;
            $details['idempotency_reuse_reason'] = $reusedReason;
        }

        return new ToolResult(
            toolCallId: $result->toolCallId,
            toolName: $result->toolName,
            content: $result->content,
            details: $details,
            isError: $result->isError,
        );
    }

    /**
     * Executes registered after-hooks with the assistant message, tool call, and result context.
     *
     * @param array<string, mixed> $context
     */
    private function applyAfterHooks(
        AgentMessage $assistantMessage,
        ToolCall $toolCall,
        ToolResult $result,
        CancellationTokenInterface $cancelToken,
        array $context,
    ): ToolResult {
        $resolved = $result;

        foreach ($this->afterToolCallHooks as $hook) {
            $after = $hook->afterToolCall(new AfterToolCallContext(
                assistantMessage: $assistantMessage,
                toolCall: $toolCall,
                args: $toolCall->arguments,
                result: $resolved,
                isError: $resolved->isError,
                context: $context,
            ), $cancelToken);

            if (null === $after) {
                continue;
            }

            $resolved = new ToolResult(
                toolCallId: $resolved->toolCallId,
                toolName: $resolved->toolName,
                content: $after->hasContentOverride ? $after->content : $resolved->content,
                details: $after->hasDetailsOverride ? $after->details : $resolved->details,
                isError: $after->isError ?? $resolved->isError,
            );
        }

        return $resolved;
    }

    /**
     * Validates tool arguments against a JSON schema and returns error message if invalid.
     *
     * @param array<string, mixed>      $arguments
     * @param array<string, mixed>|null $schema
     */
    private function validateArguments(array $arguments, ?array $schema): ?string
    {
        if (null === $schema) {
            return null;
        }

        if ('object' !== ($schema['type'] ?? 'object')) {
            return null;
        }

        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $requiredField) {
            if (!\is_string($requiredField)) {
                continue;
            }

            if (!\array_key_exists($requiredField, $arguments)) {
                return \sprintf('Invalid tool arguments: missing required field "%s".', $requiredField);
            }
        }

        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $propertyName => $propertySchema) {
            if (!\is_string($propertyName) || !\is_array($propertySchema)) {
                continue;
            }

            if (!\array_key_exists($propertyName, $arguments)) {
                continue;
            }

            $expectedType = \is_string($propertySchema['type'] ?? null) ? $propertySchema['type'] : null;
            if (null === $expectedType) {
                continue;
            }

            if (!$this->matchesType($arguments[$propertyName], $expectedType)) {
                return \sprintf('Invalid tool arguments: field "%s" must be of type "%s".', $propertyName, $expectedType);
            }
        }

        return null;
    }

    private function matchesType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'array' => \is_array($value) && array_is_list($value),
            'object' => \is_array($value) && !array_is_list($value),
            default => true,
        };
    }

    /**
     * Constructs the context array passed to after-hooks from tool call and policy.
     *
     * @return array<string, mixed>
     */
    private function contextForHooks(ToolCall $toolCall, ToolExecutionPolicy $policy, ?string $toolIdempotencyKey): array
    {
        $context = $toolCall->context;

        $context['tool_name'] = $toolCall->toolName;
        $context['tool_call_id'] = $toolCall->toolCallId;
        $context['mode'] = $policy->mode->value;
        $context['timeout_seconds'] = $policy->timeoutSeconds;
        $context['max_parallelism'] = $policy->maxParallelism;

        if (null !== $toolIdempotencyKey && '' !== $toolIdempotencyKey) {
            $context['tool_idempotency_key'] = $toolIdempotencyKey;
        }

        return $context;
    }

    private function runId(ToolCall $toolCall): ?string
    {
        if (null !== $toolCall->runId && '' !== $toolCall->runId) {
            return $toolCall->runId;
        }

        $runId = $toolCall->context['run_id'] ?? null;

        return \is_string($runId) && '' !== $runId ? $runId : null;
    }

    private function cancellationToken(ToolCall $toolCall): CancellationTokenInterface
    {
        $token = $toolCall->context['cancel_token'] ?? null;

        return $token instanceof CancellationTokenInterface ? $token : new NullCancellationToken();
    }

    private function canUseSymfonyToolbox(): bool
    {
        return null !== $this->toolbox
            && method_exists($this->toolbox, 'execute')
            && class_exists('Symfony\\AI\\Platform\\Result\\ToolCall');
    }

    private function toSymfonyToolCall(ToolCall $toolCall): object
    {
        $toolCallClass = 'Symfony\\AI\\Platform\\Result\\ToolCall';

        if (!class_exists($toolCallClass)) {
            throw new \RuntimeException('Symfony ToolCall class is unavailable.');
        }

        return new $toolCallClass(
            $toolCall->toolCallId,
            $toolCall->toolName,
            $toolCall->arguments,
        );
    }

    private function interruptResult(ToolCall $toolCall): ToolResult
    {
        $questionId = \is_string($toolCall->arguments['question_id'] ?? null)
            ? $toolCall->arguments['question_id']
            : hash('sha256', \sprintf('%s|%s', $toolCall->toolCallId, $toolCall->toolName));

        $prompt = \is_string($toolCall->arguments['prompt'] ?? null)
            ? $toolCall->arguments['prompt']
            : (\is_string($toolCall->arguments['question'] ?? null)
                ? $toolCall->arguments['question']
                : 'Please provide input.');

        $schema = \is_array($toolCall->arguments['schema'] ?? null)
            ? $toolCall->arguments['schema']
            : ['type' => 'string'];

        $payload = [
            'kind' => 'interrupt',
            'question_id' => $questionId,
            'prompt' => $prompt,
            'schema' => $schema,
        ];

        $content = json_encode($payload);
        if (false === $content) {
            $content = '{}';
        }

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: [[
                'type' => 'text',
                'text' => $content,
            ]],
            details: $payload,
            isError: false,
        );
    }

    /**
     * Creates a ToolResult representing a tool execution error with details.
     *
     * @param array<string, mixed> $details
     */
    private function errorResult(string $toolCallId, string $toolName, string $message, array $details = []): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            toolName: $toolName,
            content: [[
                'type' => 'text',
                'text' => $message,
            ]],
            details: $details,
            isError: true,
        );
    }

    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return false === $encoded ? '{}' : $encoded;
    }
}
