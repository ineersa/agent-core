<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolIdempotencyKeyResolverInterface;
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

    private ToolExecutionResultStore $resultStore;

    private ?FaultTolerantToolbox $faultTolerantToolbox;

    /**
     * @param array<string, array{mode?: string|null, timeout_seconds?: int|null}> $overrides
     */
    public function __construct(
        string $defaultMode,
        int $defaultTimeoutSeconds,
        int $maxParallelism,
        array $overrides = [],
        ?ToolboxInterface $toolbox = null,
        ?ToolExecutionResultStore $resultStore = null,
        private readonly ?ToolIdempotencyKeyResolverInterface $toolIdempotencyKeyResolver = null,
    ) {
        $this->policyResolver = new ToolExecutionPolicyResolver($defaultMode, $defaultTimeoutSeconds, $maxParallelism, $overrides);
        $this->resultStore = $resultStore ?? new ToolExecutionResultStore();
        $this->faultTolerantToolbox = null !== $toolbox ? new FaultTolerantToolbox($toolbox) : null;
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

        if ($this->cancellationToken($toolCall)->isCancellationRequested()) {
            $result = $this->errorResult(
                toolCallId: $toolCall->toolCallId,
                toolName: $toolCall->toolName,
                message: \sprintf('Tool "%s" result marked stale due to run cancellation.', $toolCall->toolName),
                details: [
                    'stale_due_to_cancel' => true,
                ],
            );
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
        if (ToolExecutionMode::Interrupt === $policy->mode || 'ask_user' === $toolCall->toolName) {
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

        return $this->toDomainResult(
            $toolCall,
            $this->faultTolerantToolbox->execute(new SymfonyToolCall(
                id: $toolCall->toolCallId,
                name: $toolCall->toolName,
                arguments: $toolCall->arguments,
            )),
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

        return new ToolResult(
            toolCallId: $toolCall->toolCallId,
            toolName: $toolCall->toolName,
            content: [[
                'type' => 'text',
                'text' => $this->normalizeResultText($rawResult),
            ]],
            details: $details,
            isError: false,
        );
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

        $encoded = json_encode($result);

        return false === $encoded ? '{}' : $encoded;
    }
}
