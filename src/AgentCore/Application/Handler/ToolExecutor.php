<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolIdempotencyKeyResolverInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionPolicy;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;

final class ToolExecutor implements ToolExecutorInterface
{
    private ToolExecutionPolicyResolver $policyResolver;

    private ?FaultTolerantToolbox $faultTolerantToolbox;

    /** @var list<ToolResultProcessorInterface> */
    private readonly array $toolResultProcessors;

    /**
     * @param iterable<ToolResultProcessorInterface> $toolResultProcessors
     */
    public function __construct(
        string $defaultMode,
        ?int $defaultTimeoutSeconds,
        int $maxParallelism,
        private readonly ToolExecutionResultStore $resultStore,
        ?ToolboxInterface $toolbox = null,
        private readonly ?ToolIdempotencyKeyResolverInterface $toolIdempotencyKeyResolver = null,
        private readonly ?StackToolExecutionContextAccessor $contextAccessor = null,
        private readonly ?ToolSetResolverInterface $toolSetResolver = null,
        iterable $toolResultProcessors = [],
    ) {
        $this->policyResolver = new ToolExecutionPolicyResolver($defaultMode, $defaultTimeoutSeconds, $maxParallelism);
        $this->faultTolerantToolbox = null !== $toolbox ? new FaultTolerantToolbox($toolbox) : null;
        $this->toolResultProcessors = \is_array($toolResultProcessors)
            ? array_values($toolResultProcessors)
            : iterator_to_array($toolResultProcessors, false);
    }

    /**
     * @param iterable<ToolResultProcessorInterface> $toolResultProcessors
     */
    public static function fromSettings(
        ToolExecutionSettingsInterface $settings,
        ToolExecutionResultStore $resultStore,
        ?ToolboxInterface $toolbox = null,
        ?ToolIdempotencyKeyResolverInterface $toolIdempotencyKeyResolver = null,
        ?StackToolExecutionContextAccessor $contextAccessor = null,
        ?ToolSetResolverInterface $toolSetResolver = null,
        iterable $toolResultProcessors = [],
    ): self {
        return new self(
            defaultMode: $settings->defaultMode(),
            defaultTimeoutSeconds: $settings->defaultTimeoutSeconds(),
            maxParallelism: $settings->maxParallelism(),
            resultStore: $resultStore,
            toolbox: $toolbox,
            toolIdempotencyKeyResolver: $toolIdempotencyKeyResolver,
            contextAccessor: $contextAccessor,
            toolSetResolver: $toolSetResolver,
            toolResultProcessors: $toolResultProcessors,
        );
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $policy = $this->resolvePolicy($toolCall);
        $runId = $this->runId($toolCall);
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

        $startedAt = hrtime(true);

        try {
            $result = $this->executeToolCall($toolCall, $policy);
        } catch (\Throwable $exception) {
            if ($exception instanceof ToolCallException) {
                $message = $exception->getMessage();
                if (null !== $exception->hint()) {
                    $message .= "\nHint: ".$exception->hint();
                }
                $details = [
                    'error_type' => ToolCallException::class,
                    'retryable' => $exception->retryable(),
                    'hint' => $exception->hint(),
                ];
                if ($this->cancellationToken($toolCall)->isCancellationRequested()) {
                    $details['cancelled'] = true;
                }
                $result = $this->errorResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    message: $message,
                    details: $details,
                );
            } else {
                $result = $this->errorResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    message: $exception->getMessage(),
                    details: ['error_type' => $exception::class],
                );
            }
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        if (null !== $policy->timeoutSeconds && $durationMs > $policy->timeoutSeconds * 1000) {
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

        if ($this->cancellationToken($toolCall)->isCancellationRequested()) {
            // Don't overwrite a structured cancelled result.
            $details = $result->details;
            $cancelled = \is_array($details) ? ($details['cancelled'] ?? false) : false;
            $alreadyCancelled = true === $cancelled;

            if (!$alreadyCancelled) {
                $errorType = \is_array($details) ? ($details['error_type'] ?? null) : null;
                if (ToolCallException::class === $errorType) {
                    $details['cancelled'] = true;
                    $result = $this->errorResult(
                        toolCallId: $toolCall->toolCallId,
                        toolName: $toolCall->toolName,
                        message: (string) ($result->content[0]['text'] ?? ''),
                        details: $details,
                    );
                } else {
                    $result = $this->errorResult(
                        toolCallId: $toolCall->toolCallId,
                        toolName: $toolCall->toolName,
                        message: \sprintf('Tool "%s" result marked stale due to run cancellation.', $toolCall->toolName),
                        details: [
                            'stale_due_to_cancel' => true,
                        ],
                    );
                }
            }
        }

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
        if (ToolExecutionMode::Interrupt === $policy->mode || 'ask_user' === $toolCall->toolName || 'ask_human' === $toolCall->toolName) {
            return $this->interruptResult($toolCall);
        }

        if (null === $this->faultTolerantToolbox) {
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

        // Enforce execution allowlist when a toolSetResolver and tools_ref are available.
        // This ensures only tools in the same snapshot that was shown to the LLM are executed.
        $allowlistCheck = $this->checkAllowlist($toolCall);
        if (null !== $allowlistCheck) {
            return $allowlistCheck;
        }

        return $this->toDomainResult(
            $toolCall,
            $this->executeWithContext($toolCall, $policy, fn () => $this->faultTolerantToolbox->execute(new SymfonyToolCall(
                id: $toolCall->toolCallId,
                name: $toolCall->toolName,
                arguments: $toolCall->arguments,
            ))),
        );
    }

    private function executeWithContext(ToolCall $toolCall, ToolExecutionPolicy $policy, callable $callback): SymfonyToolResult
    {
        if (null === $this->contextAccessor) {
            /** @var SymfonyToolResult $result */
            $result = $callback();

            return $result;
        }

        $batchToolCallCount = max(1, (int) ($toolCall->context['assistant_batch_tool_call_count'] ?? 1));

        $context = new ToolContext(
            runId: $this->runId($toolCall) ?? '',
            turnNo: (int) ($toolCall->context['turn_no'] ?? 0),
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            cancellationToken: $this->cancellationToken($toolCall),
            timeoutSeconds: $policy->timeoutSeconds,
            orderIndex: $toolCall->orderIndex,
            executionMode: $policy->mode,
            batchToolCallCount: $batchToolCallCount,
        );

        /** @var SymfonyToolResult $result */
        $result = $this->contextAccessor->with($context, $callback);

        return $result;
    }

    private function resolvePolicy(ToolCall $toolCall): ToolExecutionPolicy
    {
        $resolved = $this->policyResolver->resolve($toolCall->toolName);

        return new ToolExecutionPolicy(
            mode: $toolCall->mode ?? $resolved->mode,
            timeoutSeconds: $this->resolveTimeoutSeconds($toolCall->timeoutSeconds, $resolved->timeoutSeconds),
            maxParallelism: max(1, (int) ($toolCall->context['max_parallelism'] ?? $resolved->maxParallelism)),
        );
    }

    private function resolveTimeoutSeconds(?int $callTimeout, ?int $resolvedTimeout): ?int
    {
        $effective = $callTimeout ?? $resolvedTimeout;
        if (null === $effective || $effective <= 0) {
            return null;
        }

        return max(1, $effective);
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

    private function toDomainResult(ToolCall $toolCall, SymfonyToolResult $toolboxResult): ToolResult
    {
        $rawResult = $toolboxResult->getResult();

        $details = [
            'raw_result' => $rawResult,
        ];

        $sources = $this->normalizeSources($toolboxResult->getSources());
        if ([] !== $sources) {
            $details['sources'] = $sources;
        }

        if (\is_array($rawResult) && 'interrupt' === ($rawResult['kind'] ?? null)) {
            $details = array_replace($details, $rawResult);
        }

        $result = new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: [[
                'type' => 'text',
                'text' => $this->normalizeResultText($rawResult),
            ]],
            details: $details,
            isError: false,
        );

        // Apply registered tool-result processors (e.g. OutputCap).
        // Processors may modify content, attach model_notifications, or
        // replace the result entirely — but must never throw.
        foreach ($this->toolResultProcessors as $processor) {
            $result = $processor->process($result, $toolCall);
        }

        return $result;
    }

    /**
     * @return list<array{name: string, reference: string, content: string}>
     */
    private function normalizeSources(?SourceCollection $sources): array
    {
        if (null === $sources) {
            return [];
        }

        $normalized = [];
        foreach ($sources->all() as $source) {
            $normalized[] = [
                'name' => $source->getName(),
                'reference' => $source->getReference(),
                'content' => $source->getContent(),
            ];
        }

        return $normalized;
    }

    private function checkAllowlist(ToolCall $toolCall): ?ToolResult
    {
        if (null === $this->toolSetResolver) {
            return null;
        }

        $toolsRef = $toolCall->context['tools_ref'] ?? null;
        if (!\is_string($toolsRef) || '' === $toolsRef) {
            return null;
        }

        $turnNo = isset($toolCall->context['turn_no']) && \is_int($toolCall->context['turn_no'])
            ? $toolCall->context['turn_no']
            : null;

        $activeSet = $this->toolSetResolver->resolve($toolsRef, $turnNo, $toolCall->runId);

        if (\in_array($toolCall->toolName, $activeSet->allowListNames, true)) {
            return null;
        }

        $available = [] !== $activeSet->allowListNames
            ? implode(', ', $activeSet->allowListNames)
            : '(none)';

        return $this->errorResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            message: \sprintf(
                'Tool "%s" is not in the active execution allowlist. Available tools: %s',
                $toolCall->toolName,
                $available,
            ),
            details: [
                'denied' => true,
                'reason' => 'not_in_active_allowlist',
                'tools_ref' => $toolsRef,
                'available_tools' => $activeSet->allowListNames,
            ],
        );
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

    private function interruptResult(ToolCall $toolCall): ToolResult
    {
        // Resolve prompt — empty strings fall back (parity with AskHumanTool)
        $prompt = \is_string($toolCall->arguments['prompt'] ?? null) && '' !== $toolCall->arguments['prompt']
            ? $toolCall->arguments['prompt']
            : (\is_string($toolCall->arguments['question'] ?? null) && '' !== $toolCall->arguments['question']
                ? $toolCall->arguments['question']
                : 'Please provide input.');

        // question_id: explicit wins; ask_human gets content-stable hash
        if (\is_string($toolCall->arguments['question_id'] ?? null) && '' !== $toolCall->arguments['question_id']) {
            $questionId = $toolCall->arguments['question_id'];
        } elseif ('ask_human' === $toolCall->toolName) {
            $questionId = $this->buildAskHumanQuestionId($toolCall->arguments, $prompt);
        } else {
            $questionId = hash('sha256', \sprintf('%s|%s', $toolCall->toolCallId, $toolCall->toolName));
        }

        $schema = $this->resolveInterruptSchema($toolCall->arguments);
        $choices = $this->normalizeInterruptChoices($toolCall->arguments);
        $kind = $this->resolveInterruptKind($toolCall->arguments, $schema, $choices);

        $payload = [
            'kind' => 'interrupt',
            'question_id' => $questionId,
            'prompt' => $prompt,
            'schema' => $schema,
            'ui_kind' => $kind,
        ];

        $header = $toolCall->arguments['header'] ?? null;
        if (\is_string($header) && '' !== $header) {
            $payload['header'] = $header;
        }

        if ([] !== $choices) {
            $payload['choices'] = $choices;
        }

        if (\array_key_exists('default', $toolCall->arguments)) {
            $payload['default'] = $toolCall->arguments['default'];
        }

        $allowOther = $toolCall->arguments['allow_other'] ?? null;
        if (\is_bool($allowOther)) {
            $payload['allow_other'] = $allowOther;
        }

        $secret = $toolCall->arguments['secret'] ?? null;
        if (\is_bool($secret)) {
            $payload['secret'] = $secret;
        }

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
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function resolveInterruptSchema(array $arguments): array
    {
        if (isset($arguments['schema']) && \is_array($arguments['schema'])) {
            return $arguments['schema'];
        }

        // Derive from kind
        $kind = $arguments['kind'] ?? $arguments['ui_kind'] ?? null;

        if ('confirm' === $kind || 'approval' === $kind) {
            return ['type' => 'boolean'];
        }

        $choices = $arguments['choices'] ?? null;
        if (\is_array($choices) && [] !== $choices) {
            $enumValues = $this->extractInterruptEnumValues($choices);

            return [] !== $enumValues
                ? ['type' => 'string', 'enum' => $enumValues]
                : ['type' => 'string'];
        }

        return ['type' => 'string'];
    }

    /**
     * @param array<string, mixed>                                             $arguments
     * @param array<string, mixed>                                             $schema
     * @param list<array{label: string, description?: string, value?: string}> $choices
     */
    private function resolveInterruptKind(array $arguments, array $schema, array $choices): string
    {
        $explicit = $arguments['kind'] ?? $arguments['ui_kind'] ?? null;
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }

        // Derive from schema
        if (isset($schema['type']) && 'boolean' === $schema['type']) {
            return 'confirm';
        }

        if ([] !== $choices) {
            return 'choice';
        }

        return 'text';
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{label: string, description: string, value?: string}>
     */
    private function normalizeInterruptChoices(array $arguments): array
    {
        $raw = $arguments['choices'] ?? null;
        if (!\is_array($raw) || [] === $raw) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $item) {
            if (\is_string($item)) {
                $normalized[] = ['label' => $item, 'description' => ''];
            } elseif (\is_array($item)) {
                $entry = [];
                if (isset($item['label']) && \is_string($item['label'])) {
                    $entry['label'] = $item['label'];
                } elseif (isset($item['value']) && \is_string($item['value'])) {
                    $entry['label'] = $item['value'];
                } else {
                    continue;
                }

                // Always include description (empty string when absent)
                $entry['description'] = '';
                if (isset($item['description']) && \is_string($item['description']) && '' !== $item['description']) {
                    $entry['description'] = $item['description'];
                }

                if (isset($item['value']) && \is_string($item['value'])) {
                    $entry['value'] = $item['value'];
                }

                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $choices
     *
     * @return list<string>
     */
    private function extractInterruptEnumValues(array $choices): array
    {
        $enum = [];
        foreach ($choices as $choice) {
            if (\is_string($choice)) {
                $enum[] = $choice;
            } elseif (\is_array($choice) && isset($choice['value']) && \is_string($choice['value'])) {
                $enum[] = $choice['value'];
            } elseif (\is_array($choice) && isset($choice['label']) && \is_string($choice['label'])) {
                $enum[] = $choice['label'];
            }
        }

        return $enum;
    }

    /**
     * Build a content-stable question_id for ask_human tool calls.
     *
     * Mirrors AskHumanTool::resolveQuestionId() algorithm so that
     * the defensive fallback path in ToolExecutor produces the same
     * stable question_id as the direct handler path. AgentCore must
     * not depend on CodingAgent so this is implemented locally.
     *
     * @param array<string, mixed> $arguments
     */
    private function buildAskHumanQuestionId(array $arguments, string $prompt): string
    {
        $hashInput = $prompt;

        $schema = $arguments['schema'] ?? null;
        if (\is_array($schema)) {
            $encoded = json_encode($schema, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= \is_string($encoded) ? $encoded : '';
        }

        $kind = $arguments['kind'] ?? $arguments['ui_kind'] ?? null;
        if (\is_string($kind)) {
            $hashInput .= '/kind:'.$kind;
        }

        $choices = $arguments['choices'] ?? null;
        if (\is_array($choices) && [] !== $choices) {
            $encoded = json_encode($choices, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= '/choices:'.(\is_string($encoded) ? $encoded : '');
        }

        $header = $arguments['header'] ?? null;
        if (\is_string($header) && '' !== $header) {
            $hashInput .= '/header:'.$header;
        }

        return 'ah_'.substr(hash('sha256', $hashInput), 0, 24);
    }

    /**
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

    private function normalizeResultText(mixed $result): string
    {
        if (null === $result) {
            return '';
        }

        if (\is_string($result)) {
            return $result;
        }

        if (\is_scalar($result)) {
            return (string) $result;
        }

        if ($result instanceof \Stringable) {
            return (string) $result;
        }

        $encoded = json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }
}
